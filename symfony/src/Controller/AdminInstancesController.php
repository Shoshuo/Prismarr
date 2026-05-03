<?php

namespace App\Controller;

use App\Entity\ServiceInstance;
use App\Service\ConfigService;
use App\Service\HealthService;
use App\Service\ServiceInstanceProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CRUD on the service_instance table — backs the multi-instance admin UI
 * introduced in v1.1.0 Phase B (issue #21).
 *
 * One route per action, all CSRF-protected, all redirect back to
 * /admin/settings with a flash. The HTML form lives in the admin/settings
 * Twig template (modale add/edit + inline buttons for delete /
 * set-default / toggle-enabled).
 *
 * Cache invalidation: every mutation drops the in-request provider cache
 * (the provider does it itself), the response-cache pools (so stale Radarr
 * data fetched with the previous URL doesn't linger), and the HealthService
 * cache (so the next ping uses the new URL).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/instances', name: 'admin_instances_')]
class AdminInstancesController extends AbstractController
{
    public function __construct(
        private readonly ServiceInstanceProvider $instances,
        private readonly ConfigService $config,
        private readonly HealthService $health,
        #[Autowire(service: 'cache.app')]
        private readonly AdapterInterface $appCache,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/{type}/add', name: 'add', methods: ['POST'], requirements: ['type' => 'radarr|sonarr'])]
    public function add(string $type, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('admin_instance_add_' . $type, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', $this->translator->trans('flash.csrf.invalid'));
            return $this->redirectToRoute('admin_settings_index');
        }

        try {
            $this->instances->create(
                type:    $type,
                name:    (string) $request->request->get('name', ''),
                url:     (string) $request->request->get('url', ''),
                apiKey:  (string) $request->request->get('api_key', ''),
                slug:    $this->trimToNull($request->request->get('slug')),
                enabled: $request->request->get('enabled', '1') === '1',
            );
            $this->afterMutation();
            $this->addFlash('success', $this->translator->trans('admin.instances.flash.created'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToServicesAnchor();
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): RedirectResponse
    {
        $instance = $this->instances->findById($id);
        if ($instance === null) {
            $this->addFlash('danger', $this->translator->trans('admin.instances.flash.not_found'));
            return $this->redirectToRoute('admin_settings_index');
        }
        if (!$this->isCsrfTokenValid('admin_instance_edit_' . $id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', $this->translator->trans('flash.csrf.invalid'));
            return $this->redirectToRoute('admin_settings_index');
        }

        try {
            $this->instances->update(
                instance: $instance,
                name:     (string) $request->request->get('name', ''),
                url:      (string) $request->request->get('url', ''),
                apiKey:   (string) $request->request->get('api_key', ''),
                slug:     $this->trimToNull($request->request->get('slug')),
                enabled:  $request->request->get('enabled', '1') === '1',
            );
            $this->afterMutation();
            $this->addFlash('success', $this->translator->trans('admin.instances.flash.updated'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToServicesAnchor();
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $instance = $this->instances->findById($id);
        if ($instance === null) {
            $this->addFlash('danger', $this->translator->trans('admin.instances.flash.not_found'));
            return $this->redirectToRoute('admin_settings_index');
        }
        if (!$this->isCsrfTokenValid('admin_instance_delete_' . $id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', $this->translator->trans('flash.csrf.invalid'));
            return $this->redirectToRoute('admin_settings_index');
        }

        $this->instances->delete($instance);
        $this->afterMutation();
        $this->addFlash('success', $this->translator->trans('admin.instances.flash.deleted'));

        return $this->redirectToServicesAnchor();
    }

    #[Route('/{id}/set-default', name: 'set_default', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function setDefault(int $id, Request $request): RedirectResponse
    {
        $instance = $this->instances->findById($id);
        if ($instance === null) {
            $this->addFlash('danger', $this->translator->trans('admin.instances.flash.not_found'));
            return $this->redirectToRoute('admin_settings_index');
        }
        if (!$this->isCsrfTokenValid('admin_instance_default_' . $id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', $this->translator->trans('flash.csrf.invalid'));
            return $this->redirectToRoute('admin_settings_index');
        }

        $this->instances->setDefault($instance);
        $this->afterMutation();
        $this->addFlash('success', $this->translator->trans('admin.instances.flash.default_set'));

        return $this->redirectToServicesAnchor();
    }

    #[Route('/{id}/toggle-enabled', name: 'toggle_enabled', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggleEnabled(int $id, Request $request): RedirectResponse
    {
        $instance = $this->instances->findById($id);
        if ($instance === null) {
            $this->addFlash('danger', $this->translator->trans('admin.instances.flash.not_found'));
            return $this->redirectToRoute('admin_settings_index');
        }
        if (!$this->isCsrfTokenValid('admin_instance_toggle_' . $id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', $this->translator->trans('flash.csrf.invalid'));
            return $this->redirectToRoute('admin_settings_index');
        }

        $this->instances->update(
            instance: $instance,
            name:     $instance->getName(),
            url:      $instance->getUrl(),
            apiKey:   null, // unchanged
            enabled:  !$instance->isEnabled(),
        );
        $this->afterMutation();
        $key = $instance->isEnabled() ? 'admin.instances.flash.enabled' : 'admin.instances.flash.disabled';
        $this->addFlash('success', $this->translator->trans($key));

        return $this->redirectToServicesAnchor();
    }

    /**
     * Probe a specific instance — health-check button on every row.
     * Returns JSON {ok, category, http} matching the existing
     * /admin/settings/test/{service} envelope so the front-end JS can
     * render the same status pill as the global "Test" button.
     */
    #[Route('/{id}/test', name: 'test', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function testInstance(int $id, Request $request): JsonResponse
    {
        $instance = $this->instances->findById($id);
        if ($instance === null) {
            return new JsonResponse(['ok' => false, 'category' => 'not_found'], 404);
        }
        if (!$this->isCsrfTokenValid('admin_instance_test_' . $id, (string) $request->request->get('_csrf_token'))) {
            return new JsonResponse(['ok' => false, 'category' => 'csrf'], 400);
        }

        $overrides = [
            $instance->getType() . '_url'     => $instance->getUrl(),
            $instance->getType() . '_api_key' => $instance->getApiKey() ?? '',
        ];
        $result = $this->health->diagnose($instance->getType(), $overrides);

        $resp = new JsonResponse([
            'ok'       => (bool) $result['ok'],
            'category' => (string) $result['category'],
            'http'     => $result['http'] ?? null,
        ]);
        $resp->headers->set('Cache-Control', 'no-store');
        return $resp;
    }

    /** Drop the response-cache pool + HealthService cache after every write. */
    private function afterMutation(): void
    {
        $this->config->invalidate();
        $this->health->invalidate();
        try { $this->appCache->clear(); } catch (\Throwable) {}
    }

    private function redirectToServicesAnchor(): RedirectResponse
    {
        // Land back on the Services section so the user sees the change in
        // context without scrolling up from the bottom of the page.
        return $this->redirectToRoute('admin_settings_index', ['_fragment' => 'section-services']);
    }

    private function trimToNull(mixed $raw): ?string
    {
        $s = trim((string) ($raw ?? ''));
        return $s === '' ? null : $s;
    }
}

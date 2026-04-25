<p align="center">
  <img src="symfony/public/img/prismarr/prismarr-logo-horizontal.png" alt="Prismarr" width="420">
</p>

<p align="center">
  <strong>Un seul dashboard pour votre stack médias self-hosted.</strong>
</p>

<p align="center">
  <a href="https://github.com/Shoshuo/Prismarr/blob/main/LICENSE"><img src="https://img.shields.io/badge/license-AGPL--3.0-blue" alt="AGPL-3.0"></a>
  <img src="https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white" alt="PHP 8.4">
  <img src="https://img.shields.io/badge/Symfony-8-000000?logo=symfony&logoColor=white" alt="Symfony 8">
  <img src="https://img.shields.io/badge/FrankenPHP-1.3-orange" alt="FrankenPHP 1.3">
  <img src="https://img.shields.io/badge/SQLite-zero--config-003B57?logo=sqlite&logoColor=white" alt="SQLite">
</p>

<p align="center">
  <a href="#fonctionnalités">Fonctionnalités</a> ·
  <a href="#démarrage-rapide">Démarrage rapide</a> ·
  <a href="#configuration">Configuration</a> ·
  <a href="#mise-à-jour">Mise à jour</a> ·
  <a href="#dépannage">Dépannage</a> ·
  <a href="#roadmap">Roadmap</a> ·
  <a href="#licence">Licence</a>
</p>

---

## À propos

**Prismarr** réunit qBittorrent, Radarr, Sonarr, Prowlarr, Jellyseerr et
TMDb dans une seule interface Symfony moderne. Plus besoin de jongler
entre six onglets pour gérer votre bibliothèque.

Ce n'est pas un remplaçant de Radarr ou de Sonarr - ces services tournent
en parallèle et continuent de faire ce qu'ils font de mieux. Prismarr est
la surface de contrôle unifiée : une barre de recherche qui couvre la
bibliothèque locale et TMDb, un calendrier qui fusionne sorties films et
épisodes, un dashboard qui remonte ce qui compte aujourd'hui (un téléchargement
récent, une requête en attente, une tendance), et une page paramètres où
chaque clé API est stockée - jamais en clair sur disque, jamais en variable
d'environnement.

Le tout dans un seul container Docker avec SQLite embarqué. Au premier
démarrage, un wizard 7 étapes : créer l'admin, brancher les services,
terminé. Pas de BDD externe, pas de Redis, pas de fichiers `.env` par
service. Pull de l'image, un volume monté, c'est en route.

---

## Fonctionnalités

### Gestion médias unifiée
- Films (Radarr) et Séries (Sonarr) avec cinq modes de vue
- Recherche globale `Ctrl+K` qui couvre la bibliothèque locale + TMDb / TheTVDB
- Modal d'ajout rapide accessible depuis n'importe quelle page
- Calendrier unifié (sorties films + épisodes) avec vues Mois / Semaine / Jour
  et export iCal

### Dashboard
- Hero spotlight avec un film tiré au hasard dans votre bibliothèque
- Sorties à venir (mini-calendrier sur 7 jours)
- Requêtes Jellyseerr en attente, enrichies avec les métadonnées TMDb
- État en temps réel des six services
- Watchlist personnelle, tendances TMDb hebdomadaires, derniers ajouts

### Téléchargements
- Dashboard qBittorrent complet : pagination, tri et filtres côté serveur
- Upload `.torrent` drag-and-drop (multi-fichiers)
- Badges pipeline : cliquer sur un torrent ouvre directement le film/série
- Toasts cross-tab à la fin des téléchargements
- Intégration Gluetun optionnelle : IP publique, pays, sync port forwarding

### Découverte
- Page TMDb : hero, recommandations personnalisées, tendances
- Watchlist personnelle, Explorer avec filtres (genre / décennie / casting)
- Countdown des sorties à venir
- Deep-links vers votre bibliothèque existante

### Profil et préférences
- Page `/profil` : modifier nom d'affichage, mot de passe et avatar
  (JPG / PNG / WebP / GIF, 2 Mo max)
- Préférences d'affichage : couleur du thème, densité UI, toasts, fuseau
  horaire, format date / heure, auto-refresh qBit, page d'accueil par défaut
- UI Anglais / Français (EN par défaut, FR entièrement traduit, support des
  pluriels ICU)
- Export / import des paramètres (les credentials sont toujours strippés)

### Sécurité
- Authentification Symfony avec rate-limiter sur le login (5 tentatives par
  IP+username / 15 min)
- Container non-root, Content-Security-Policy dynamique
- Protection SSRF sur les URLs fournies par l'utilisateur (whitelist
  protocoles, blocklist cloud-metadata)
- Tokens CSRF sur chaque mutation, pages d'erreur brandées qui n'exposent
  jamais les données d'exception
- Routes profiler retournent 403 pour les clients non-RFC1918 en dev

---

## Démarrage rapide

### Pré-requis

- Docker et Docker Compose
- Au moins un de : qBittorrent, Radarr, Sonarr, Prowlarr, Jellyseerr
- Optionnel : Gluetun si qBittorrent tourne derrière un VPN
- Optionnel : une clé API TMDb (gratuite) pour activer la page Découverte

### Installation

```bash
# 1. Télécharger le template compose user-facing
wget -O docker-compose.yml https://raw.githubusercontent.com/Shoshuo/Prismarr/main/docker-compose.example.yml

# 2. Démarrer le container
docker compose up -d

# 3. Ouvrir http://localhost:7070
#    Le setup wizard guide :
#      - création du compte admin
#      - clé API TMDb
#      - URLs et clés Radarr / Sonarr / Prowlarr / Jellyseerr
#      - qBittorrent + Gluetun (optionnel)
```

> Le fichier s'appelle `docker-compose.example.yml` dans le repo pour que
> les contributeurs qui clonent les sources ne lancent pas par accident
> l'image de prod au lieu du build de dev. Le renommer en local est juste
> un confort.

`APP_SECRET` et `MERCURE_JWT_SECRET` sont auto-générés au premier démarrage et
persistés dans le volume `prismarr_data`. Aucune édition de `.env` requise.

### Port par défaut

Prismarr écoute sur le port `7070`. Pour utiliser un autre port, changer la
partie gauche du mapping dans `docker-compose.yml` :

```yaml
ports:
  - "8080:7070"  # accessible sur http://localhost:8080
```

---

## Configuration

Tout se configure depuis l'interface :

- **Premier démarrage** : le wizard 7 étapes à `/setup`
- **Après** : la page Paramètres `/admin/settings` (admin uniquement)

Les credentials des services externes (clés API TMDb / Radarr / Sonarr /
Prowlarr / Jellyseerr, mot de passe qBittorrent, URLs des services), les
préférences d'affichage et la langue sont stockés dans la BDD SQLite
(table `setting`). Ils n'apparaissent jamais dans les variables
d'environnement ni dans aucun fichier committable.

Deux secrets framework - `APP_SECRET` et `MERCURE_JWT_SECRET` - sont
auto-générés au premier démarrage et persistés dans le volume sous
`var/data/.env.local`. Ils ne quittent jamais le volume ; aucun besoin de
les définir, faire tourner ou sauvegarder à la main.

### Variables d'environnement (optionnelles)

| Variable | Défaut | Rôle |
|---|---|---|
| `APP_ENV` | `prod` | Passer à `dev` uniquement pour le développement local |
| `PRISMARR_PORT` | `7070` | Port interne d'écoute |
| `TRUSTED_PROXIES` | `127.0.0.1,REMOTE_ADDR` | À ajuster si derrière Traefik / nginx / Caddy / Cloudflare Tunnel |

### Données persistantes

Tout vit dans le volume Docker `prismarr_data` :

- `prismarr.db` (BDD SQLite)
- `.env.local` (secrets auto-générés)
- `sessions/` (sessions de connexion)
- `cache/` (vignettes TMDb / covers)
- `avatars/` (avatars uploadés)

Backup standard :
`docker run --rm -v prismarr_data:/data -v $(pwd):/backup alpine tar czf /backup/prismarr-data.tgz -C /data .`

### Reverse proxy

Prismarr gère lui-même les headers HSTS et Permissions-Policy. Quand l'app
est derrière un reverse proxy qui termine TLS (Traefik, nginx, Caddy,
Cloudflare Tunnel), définir `TRUSTED_PROXIES` pour que Symfony lise
correctement les headers `X-Forwarded-*`.

---

## Mise à jour

```bash
docker compose pull
docker compose up -d
```

Les migrations SQLite s'appliquent automatiquement au démarrage. Le volume
`prismarr_data` est préservé.

Pour pinner une version spécifique au lieu de `latest` :

```yaml
services:
  prismarr:
    image: prismarr/prismarr:1.0.0
```

---

## Dépannage

### Mot de passe admin oublié

```bash
docker exec -it prismarr php bin/console app:user:reset-password <email>
```

### Le wizard de setup boucle indéfiniment

Le wizard se termine quand le flag `setup_completed` est posé. Pour le
remettre à l'étape 1 :

```bash
docker exec -it prismarr php bin/console doctrine:query:sql \
  "DELETE FROM setting WHERE key = 'setup_completed'"
```

### Le healthcheck retourne 503

`GET /api/health` retourne 503 quand SQLite est inaccessible. Inspecter les
logs du container :

```bash
docker logs prismarr --tail 200
```

La cause la plus fréquente est un volume corrompu après un disk full sur
l'hôte. Restaurer le dernier backup est le chemin le plus rapide.

### Le container ne démarre pas

```bash
docker logs prismarr
```

Si l'erreur mentionne `permission denied` sur le volume, le filesystem
hôte empêche l'utilisateur `www-data` du container (UID 33 par défaut)
d'écrire. S'assurer que le volume est un volume Docker managé et pas un bind
mount sur un répertoire appartenant à root.

---

## Roadmap

### v1.0 - Release publique
- [x] Wizard de setup 7 étapes
- [x] Authentification avec rate-limiter login
- [x] Migrations Doctrine (mises à jour propres)
- [x] Suite PHPUnit (179 tests / 376 assertions)
- [x] Image Docker multi-architecture (amd64 + arm64)
- [x] UI Anglais / Français (EN source de vérité)
- [x] Page admin paramètres (services, affichage, langues, backup)
- [x] Dashboard, Calendrier (mois / semaine / jour + export iCal), Profil
- [x] Publiée sur Docker Hub

### v1.x - Améliorations
- [ ] Multi-utilisateurs avec rôles (lecture seule vs admin)
- [ ] Widget Jellyfin (sessions live + stats)
- [ ] Notifications Discord / Ntfy / Telegram
- [ ] Graphiques historiques de bande passante
- [ ] API REST publique pour intégrations tierces

### v2.0 - Automation
- [ ] Auto-import d'une bibliothèque existante
- [ ] Règles de traitement personnalisées
- [ ] Backend MariaDB / PostgreSQL en option

---

## Stack technique

- **Backend** : PHP 8.4 / Symfony 8 / Doctrine ORM
- **Serveur** : FrankenPHP (Caddy + PHP embed, mode worker) supervisé par s6-overlay
- **Frontend** : Tabler UI + Alpine.js + Turbo (Hotwire) via Symfony AssetMapper
- **BDD** : SQLite (zéro-config, migrations Doctrine automatiques)
- **Cache + sessions** : filesystem (pas besoin de Redis)
- **Queue** : Symfony Messenger (transport Doctrine)
- **Temps réel** : Mercure SSE intégré à Caddy

Un seul container Docker embarque tout. L'image fait `~282 Mo` et tourne sur
`amd64` et `arm64`.

---

## Contribuer

Les contributions sont les bienvenues - merci d'ouvrir une issue d'abord pour
discuter du scope avant de soumettre une PR.

- **Guide contributeur** : [CONTRIBUTING.md](CONTRIBUTING.md) (Definition of Done + règles d'or)
- **Code de conduite** : [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) (Contributor Covenant 2.1)
- **Vulnérabilité de sécurité** : [SECURITY.md](SECURITY.md) - **ne pas** ouvrir d'issue publique, contact par email
- **Changelog** : [CHANGELOG.md](CHANGELOG.md)

Avant tout commit : `make check` (lint PHP + lint Twig + suite PHPUnit complète).

---

## Construit avec l'aide d'une IA

Prismarr a été développé en collaboration étroite avec [Claude Code](https://claude.com/claude-code) (Anthropic). Pour rester transparent, voici les domaines concrets où l'IA a été activement utile :

**Usages principaux**

- **Traduction i18n et insertion des clés** - l'anglais n'est pas ma langue natale ; Claude a traité l'essentiel des fichiers YAML EN/FR (4 188 clés de chaque côté, parité exacte maintenue) et les appels `trans()` côté PHP et Twig.
- **Debug des logs et du JavaScript** - triage plus rapide des stack traces, des comportements Turbo/Alpine et des edge cases front que je n'arrivais pas à reproduire en local.
- **Listage des endpoints d'API** - cartographier les ~600 endpoints Radarr v3, Sonarr v3, Prowlarr v1, Jellyseerr, qBittorrent v2 et TMDb v3 à partir de leurs specs OpenAPI.
- **Debug des tests unitaires PHPUnit** - transformer des assertions ratées en diffs lisibles.
- **Design responsive mobile** - rendre Prismarr fluide au téléphone (vues calendrier semaine/jour, repli sidebar, grilles widgets dashboard).
- **Revue et durcissement sécurité** - second avis sur SSRF, CSP, tokens CSRF, XSS, patterns d'injection SQL/XML, exposition du profiler.
- **Audit du code** - remonter les traductions manquées, les edge cases oubliés et les bugs dans mon propre code.
- **Traduction et polish de la documentation** - README, CHANGELOG, CONTRIBUTING, SECURITY, CODE_OF_CONDUCT en anglais et en français.
- **Messages de commit locaux et fichier privé PROGRESSION.md** - tenir le journal de session lisible. Ce fichier ne vit que sur ma machine et n'est jamais poussé sur GitHub.

**Usages secondaires**

- **Scaffolding de templates Twig** - les ~50 sous-pages admin Radarr/Sonarr (quality, custom formats, indexers, downloads, notifications, metadata, tags, etc.) ont été générées puis revues page par page.
- **Workflows GitHub Actions** - le pipeline CI et celui de release Docker multi-arch.
- **Architecture single-container Docker** - le layout FrankenPHP + s6-overlay qui supervise le serveur web et le worker messenger.

Chaque ligne de code a été lue, testée en local, et validée par moi avant merge. `make check` (lint PHP + lint Twig + suite PHPUnit complète) devait être vert. L'IA a accéléré l'implémentation ; j'ai gardé le jugement d'ingénierie et la responsabilité du projet.

---

## Licence

[AGPL-3.0](LICENSE) - vous pouvez utiliser, modifier et redistribuer Prismarr
librement, y compris en self-hosted production. Les dérivés doivent rester
open source sous la même licence.

---

## Remerciements

Inspiré par les travaux remarquables de :

- [Overseerr / Jellyseerr](https://github.com/Fallenbagel/jellyseerr)
- La famille [Servarr](https://wiki.servarr.com/) (Radarr, Sonarr, Prowlarr, Bazarr…)
- [Tabler](https://tabler.io/) pour l'UI kit

Et, sur une note plus personnelle : merci à mes amis et ma famille pour
leur patience, leurs encouragements, et pour avoir demandé "ça sort
quand ?" assez souvent pour me faire tenir la cadence. Cette release
est pour vous.

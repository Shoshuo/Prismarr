# Test multi-instance Radarr/Sonarr

Throwaway Radarr + Sonarr containers to exercise Prismarr's multi-instance
UI (issue #21, v1.1.0). They run on dedicated ports (7879 / 8990) so they
don't clash with whatever Radarr/Sonarr you may already have on `:7878` /
`:8989`.

Everything lives next to this file (config + library mounts), all
gitignored — wipe the directory when you're done.

## Spin up

```bash
cd dev-helpers/test-multi-instance
docker compose up -d
```

Wait ~20 s for first boot, then:

| Service       | URL                     | Default port inside container |
| ------------- | ----------------------- | ----------------------------- |
| Radarr 4K     | http://localhost:7879   | 7878                          |
| Sonarr Anime  | http://localhost:8990   | 8989                          |

## First-time setup (one-shot, ~3 minutes)

For each container:

1. Open the UI, click through the auth screen (you can pick `None` for
   local-only testing).
2. **Settings → Media Management → Add Root Folder**
   - Radarr 4K → `/movies`
   - Sonarr Anime → `/tv`
3. **Settings → General → Security → API Key** → copy it.

Then in Prismarr (`http://localhost:7070/admin/settings`):

1. Click **+ Add a Radarr instance**
   - Name: `Radarr 4K`
   - URL: `http://host.docker.internal:7879`
   - API key: (paste)
2. Click **+ Add a Sonarr instance**
   - Name: `Sonarr Anime`
   - URL: `http://host.docker.internal:8990`
   - API key: (paste)

You should now see **two Radarr** and **two Sonarr** rows in the
multi-instance lists. The "Default" badge stays on the original instances
unless you explicitly promote the new ones.

## Add a single film / series for end-to-end testing

Without an indexer, just use the search:

- **Radarr 4K** → `Movies → Add New` → search `Inception` → `Add Movie`
  with monitoring on (no download will happen, the file is missing — fine
  for surfacing the entry in Prismarr).
- **Sonarr Anime** → `Series → Add New` → search `Cowboy Bebop` → `Add Series`.

The new entries should appear in Prismarr the next time the corresponding
list is loaded.

## Tear down

```bash
docker compose down
rm -rf radarr-4k-config radarr-4k-movies sonarr-anime-config sonarr-anime-tv
```

This deletes both containers and every byte they wrote — Prismarr forgets
nothing on its side, but the two test instances disappear from the
upstream services.

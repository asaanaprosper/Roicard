# Private CV storage

CVs are stored in the **private `local` disk** (`storage/app/private`), not the
public media disk used by avatars, logos, covers and catalog images.

## Deployment requirements

- Keep `storage/app/private` on persistent shared storage if the app runs
  multiple hosts.
- Ensure the web/queue user can read/write it.
- Do **not** create a public web-server alias or symlink for
  `storage/app/private`.
- Keep `MEDIA_DISK=public` (or the existing public media disk) for public
  avatars and catalog images; the `cv` collection overrides this with
  `useDisk('local')`.
- If the private disk is remote/S3, the current download endpoint and migration
  command support local filesystem disks. Adapt the disk strategy before
  switching the `cv` collection to remote storage.
- Include `storage/app/private` in the normal backup set.

## Legacy CV migration

Existing CVs may remain on the old public disk. Run the safe migration command
first in dry-run mode, then in staging:

```bash
php artisan deploy:preflight
php artisan media:migrate-private-cvs --dry-run
php artisan media:migrate-private-cvs
```

The command:

- copies each CV to the private disk;
- compares SHA-256 checksums before re-pointing the media row;
- refuses to overwrite a non-matching destination;
- reports missing and failed files;
- never deletes originals.

Keep the original public files until the migrated CV downloads have been
verified. Remove the originals manually only after a verified backup and review.

## Download authorization

`GET /api/profiles/{profile}/cv/{media}` is the only supported CV download URL.
Owner/admin access is always allowed. A CV marked `visible` may also be
downloaded from a profile that is published and visible to the requester.
Storage paths and direct media URLs are never returned by the API.

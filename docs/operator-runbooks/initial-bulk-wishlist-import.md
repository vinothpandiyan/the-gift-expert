# Initial bulk wishlist import — Phase 22A.4

Operator runbook for the first curated Amazon India wishlist catalog.

This is **not** recurring quarterly maintenance (Phase 22A.5). It does **not** auto-publish or bulk-approve reviews.

## Safety boundary

Every ingested Product remains `draft`, including AI Accepted. Publication stays manual through GiftResource / `PublishProductAction`.

## Prerequisites

- Source-list mappings in `config/curated_catalog.php` (`source_lists.amazon-in`)
- Queue worker only required if you pass `--queue` on classification
- Extractor: `docs/operator-tools/amazon-wishlist-extractor.js` (JSON v2)

## Sequence

### 1. Export wishlists

Export each Amazon India wishlist with the v2 extractor. Typical lists:

- Gifts for Boyfriend / Girlfriend / Husband / Wife / Father / Mother / …
- `00 - Unclassified Gift Ideas`
- Optional quarterly archive lists (`Gift Ideas - Qn YYYY`)

Do **not** merge files by hand. The application merges by merchant + ASIN.

### 2. Place JSON files in one directory

Example:

```text
storage/app/imports/amazon-wishlists/initial/
  gifts-for-boyfriend.json
  gifts-for-girlfriend.json
  gifts-for-husband.json
  …
```

### 3. Dry-run (no writes, no AI)

```bash
./vendor/bin/sail artisan catalog:curated-intake storage/app/imports/amazon-wishlists/initial --dry-run
```

Inspect:

- wishlist file count
- resolved source-list mappings (last sanity check)
- unknown / malformed / duplicate identities
- raw occurrences, unique ASINs, merged / multi-list / new / existing
- malformed ASINs
- commercial-field conflicts
- recipient-hint and source-list distribution

Fix mappings in `config/curated_catalog.php`. Do not infer relationships from free text at commit time.

### 4. Repeat dry-run until clean

Commit is blocked while any hard-stop remains:

- unknown source-list mapping
- malformed source-list identity
- duplicate logical wishlist identity (same list in more than one file)
- unsupported merchant / invalid schema version / merchant mismatch (file-level)
- significant missing / malformed ASINs
- directory file missing JSON v2 source-list identity

Small per-product errors can still isolate during ingest. Source-list mapping problems must be resolved first.

### 5. Deferred ingest (no AI)

```bash
./vendor/bin/sail artisan catalog:curated-intake storage/app/imports/amazon-wishlists/initial --defer-classification
```

Record the printed **intake run ID**.

Re-running the same directory is safe: no duplicate Product / AffiliateLink / CatalogSourceList / CatalogProductSource rows; `first_seen` is preserved; `last_seen` and occurrence counters update.

Known Products keep existing taxonomy. New deferred Products stay `taxonomy_classification_status=none`.

### 6. Classification preflight (no AI)

```bash
./vendor/bin/sail artisan catalog:classify-curated --intake-run=<id> --dry-run
```

Confirm:

- Unique Products requiring AI classification = N
- skipped current / human locked
- trusted hints vs no recipient hints
- current classification version
- estimated max output tokens (N × enrichment max_output_tokens)

`raw wishlist rows != AI calls`. Calls equal eligible unique Products in this intake run.

### 7. Classify that intake run only

```bash
./vendor/bin/sail artisan catalog:classify-curated --intake-run=<id>
```

Optional: dispatch per-product jobs instead of running in the artisan process:

```bash
./vendor/bin/sail artisan catalog:classify-curated --intake-run=<id> --queue
```

`curated_catalog.classification.max_concurrency` documents queue worker sizing. Classification is sequential per worker; do not expect a custom pool.

Progress prints processed / remaining / status per Product. One failure does not abort the batch.

### 8. Resume

Re-run the same command. `ShouldReclassifyCuratedMerchantProductAction` skips Products that are already current or human-locked. Successful AI calls are not repeated.

### 9. Retry transient failures only

```bash
./vendor/bin/sail artisan catalog:classify-curated --intake-run=<id> --status=failed --retry-failed
```

Do not retry automatically forever.

### 10. Watch / review

Command summary:

- AI Accepted / Needs Review / Failed
- Filament tabs under Admin → Catalog → Gifts:
  - Failed: `/admin/gifts?activeTab=failed`
  - Needs Review: `/admin/gifts?activeTab=review`
  - AI Accepted: `/admin/gifts?activeTab=ai_accepted`

Review order:

1. FAILED
2. trusted-source semantic conflicts
3. taxonomy gaps
4. low primary Category confidence
5. low GiftType confidence
6. other REVIEW reasons

Do **not** bulk-approve REVIEW. Do **not** auto-publish AI Accepted. Spot-check AI Accepted (see audit sample) instead of opening every accepted Gift.

### 11. Integrity audit

```bash
./vendor/bin/sail artisan catalog:curated-audit --intake-run=<id>
```

Reports unique Products, provenance, classification states, missing/multiple primaries, missing Category ancestors, semantic conflicts, unpublished/published counts, review/failed counts, commercial conflicts, residual `none` (with explanation), and an AI Accepted spot-check sample.

These should be zero:

- primary child Category missing active ancestor
- multiple primary Categories
- no primary Category on `ai_accepted` / `human_*` Products
- applied Husband+Raksha Bandhan (or other semantic) conflicts
- provenance pointing at the wrong merchant Product

Residual `none` on this intake run must be explained (skipped, missing input, queued/not processed) — not left silent.

### 12. Manually publish approved Products

Use GiftResource publish after taxonomy is `ai_accepted`, `human_approved`, or `human_overridden`.

Seeded/demo Products are not deleted by this import. The dry-run and commit report **new** vs **existing**. Cleanup of demo catalog is a separate explicit task.

## Existing Filament one-list sync

Filament Curated Product Intake remains immediate-classification for ordinary one-list paste. This runbook is for the directory bulk path with `--defer-classification`.

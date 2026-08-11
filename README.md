# The Gift Expert

Affiliate gift discovery platform. Internally the catalog entity is `Product`; customer-facing UI uses **Gift** terminology.

## Stack

- Laravel 13 / PHP 8.5
- Filament 5 admin
- Livewire 4
- Tailwind CSS + Vite
- MySQL + Redis
- Laravel Sail
- PHPUnit + Pint

## Requirements

- Docker Desktop running
- Composer dependencies installed (`composer install`)
- Node dependencies installed when building assets (`npm install`)

## Setup

```bash
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

App URL defaults to `http://localhost` (Sail port 80).

## Admin

Filament admin: `http://localhost/admin`

Seeded development user:

- Email: `test@example.com`
- Password: `password`

Do not treat this credential as production-ready.

## Public discovery

Configured in `config/discovery.php` and generated via `App\Support\DiscoveryUrl`:

| Page | Path |
|------|------|
| Gift detail | `/gifts/{slug}` |
| Category ideas | `/gift-ideas/{full_path}` |
| Finder | `/find-a-gift` |
| Finder results | `/find-a-gift/results/{uuid}` |
| Affiliate outbound | `/out/{uuid}` |

There is no public `/products` route.

## Tests

```bash
./vendor/bin/sail artisan test
./vendor/bin/sail artisan test --filter=MvpSmokeTest
./vendor/bin/sail pint --dirty
```

PHPUnit uses the `testing` database (`phpunit.xml`).

## Architecture notes

- Publication: `App\Actions\Product\PublishProductAction`
- Recommendations: `App\Actions\Recommendation\GenerateRecommendationsAction`
- Affiliate clicks: `App\Actions\Affiliate\CreateAffiliateClickAction`
- Customer terminology: `App\Support\Terminology`
- Discovery URLs: `App\Support\DiscoveryUrl`
- Public SEO helpers: `App\Support\PageMeta`

Authoritative project context lives in `.cursor/PROJECT_STATE.mdc` and `.cursor/rules/architecture.mdc`.

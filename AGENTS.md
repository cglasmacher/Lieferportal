# Lieferportal

Laravel 13 + React/TypeScript. Read README.md before working. Use the versions pinned by composer.lock/package-lock.json. Production baseline PHP 8.4; development SQLite, production MySQL.

- Keep checkout closed until authenticated merchant onboarding, real provider clients, signed webhooks, order persistence and refund handling are implemented and verified.
- Restaurants are the sellers/payment recipients. Never fall back to charging the platform when a merchant account is missing.
- Store monetary values as integer cents and use server-calculated prices. Scope every article/order/payment lookup to its restaurant and authorized actor.
- Never commit secrets, .env, vendor, node_modules or generated builds.
- Extend meaningful feature/unit tests for financial rules and tenant boundaries. Run php artisan test, npm run build and vendor/bin/pint before finishing.
- Do not seed fictional restaurants or users in production.

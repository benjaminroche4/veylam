# Veylam — CLAUDE.md

**Veylam** est le site vitrine d'une holding de sociétés. Périmètre actuel : **homepage**, **page de contact**, **pages légales**. Le site est **en anglais uniquement** pour l'instant (locale unique `en`), mais tout texte passe par le système de traduction pour permettre l'ajout de locales plus tard.

Production visée : **hébergement mutualisé o2switch** (serveur LiteSpeed, pas de workers, déploiement par `git pull` + `php composer.phar`). Plusieurs règles de ce document en découlent directement — elles sont marquées **[o2switch]**.

**Langue de travail** : réponds toujours en français. Code, variables, commits, clés de traduction et contenus du site en anglais.

---

## 1. Stack

- **PHP 8.4** — utilise systématiquement la syntaxe moderne : classes `final`, propriétés `readonly`, promotion de constructeur, enums backed, attributs PHP (`#[Route]`, `#[ORM\...]`, `#[Assert\...]`, `#[AsMessageHandler]`, `#[AsEventListener]`).
- **Symfony 8.1** (`extra.symfony.require: 8.1.*`) — toujours les pratiques les plus récentes de la doc officielle.
- **Doctrine ORM 3 / Migrations** — **MySQL 8** (dev local via MAMP, cf. `.env.local` ; MySQL/MariaDB en prod o2switch). Le `.env` et `compose.yaml` contiennent encore les défauts PostgreSQL de la recette Flex : la source de vérité est `.env.local`.
- **AssetMapper + `importmap.php`** — **zéro Webpack, zéro npm, zéro Vite, zéro Node**. Les dépendances JS s'ajoutent via `php bin/console importmap:require <pkg>`.
- **Tailwind CSS v4** via `symfonycasts/tailwind-bundle` (binaire natif, pas de PostCSS). Entrée : `assets/styles/app.css`.
- **Hotwire** : Turbo (`symfony/ux-turbo`) + Stimulus (`symfony/stimulus-bundle`).
- **Twig Components** (`symfony/ux-twig-component`) — composants anonymes dans `templates/components/`.
- **UX Icons** (`symfony/ux-icons`) — icônes via `<twig:ux:icon name="..." />`, jeux iconify téléchargés dans `assets/icons/`.
- **Messenger** — transports `sync` + `failed` (doctrine). **[o2switch] Jamais de transport async : aucun worker ne tourne en prod.** Tout message métier est routé en sync.
- **Mailer** — `null://null` en dev (ou Mailpit via Docker), DSN réel en prod via variable d'env / secrets Symfony.
- **PHPUnit 13** — tests dans `tests/`.

### Packages à ajouter au fur et à mesure du besoin (pas préventivement)
- `friendsofphp/php-cs-fixer` + `phpstan/phpstan` (+ `phpstan-doctrine`, `phpstan-symfony`) — **requis dès maintenant** : le Makefile (`make lint`, `make test-prod`) les référence déjà.
- `symfony/rate-limiter` — dès qu'un formulaire public existe (donc pour le formulaire de contact).
- `nelmio/security-bundle` — headers de sécurité + CSP avant la mise en prod.
- `presta/sitemap-bundle` — sitemap.xml avant la mise en prod.
- `twig/html-extra`, `twig/intl-extra`, `twig/string-extra` — selon besoin.
- `tales-from-a-dev/twig-tailwind-extra` — `tailwind_merge` + `html_cva` si on construit un UI kit à variants.
- `zenstruck/foundry` + `doctrine/doctrine-fixtures-bundle` — dès qu'une entité est testée.
- `giggsey/libphonenumber-for-php` — si le formulaire de contact demande un téléphone.

---

## 2. Architecture `src/` — bounded contexts

Un dossier par domaine métier, chacun avec la même sous-structure. **Nouvelle feature → nouveau contexte** (ou extension d'un contexte existant si c'est le même domaine).

Contextes prévus pour le périmètre actuel :

```
src/
├── Controller/Public/     # pages statiques sans domaine : HomeController, LegalController, SitemapController
├── Contact/               # formulaire de contact (contexte complet)
│   ├── Controller/        # ContactController
│   ├── Entity/            # Contact (persisté en DB)
│   ├── Repository/
│   ├── Form/              # ContactType
│   ├── Message/           # SendContactEmailsMessage (readonly DTO)
│   ├── MessageHandler/    # SendContactEmailsHandler (#[AsMessageHandler], sync)
│   └── Twig/Components/   # composants propres au contexte si besoin
├── Shared/                # transverse : value objects, enums (EmailAddress…), extensions Twig, validators
└── Kernel.php
```

### Conventions de code

- **Controllers** : `final class XxxController extends AbstractController`, dépendances injectées par constructeur en `private readonly`. Routes en attributs `#[Route('/contact', name: 'app_contact')]`. Noms de routes préfixés `app_`. Options sitemap déclarées inline dans l'attribut (`options: ['sitemap' => ['priority' => 0.8]]`) quand presta/sitemap sera installé.
- **Entities** : attributs `#[ORM\...]`, `repositoryClass` renseigné, contraintes `#[Assert\...]` sur l'entité, getters/setters fluent (`: static`).
- **DTOs** : `final readonly class` avec promotion de constructeur. **Jamais d'entité Doctrine dans un template — toujours un DTO** dès que la vue a besoin de données issues de la DB.
- **Enums** : backed enums PHP dans `Domain/` ou `Shared/` (ex. `EmailAddress` pour les destinataires d'emails).
- **Messages Messenger** : `final readonly class`, handler `final` avec `#[AsMessageHandler]` et `__invoke()`. Routés **sync** dans `config/packages/messenger.yaml`.
- **Event listeners** : attribut `#[AsEventListener]`, pas de config YAML.
- **Services** : autowire/autoconfigure (défaut `services.yaml`). Déclaration explicite uniquement si arguments env/scalaires.
- Jamais de logique métier dans un controller : le controller orchestre (form → dispatch → réponse), la logique vit dans les services/handlers.

---

## 3. Templates — architecture en 4 niveaux

```
templates/
├── _partials/             # partials techniques, préfixe _ : meta, schema JSON-LD, tracking
│   ├── meta.html.twig     # OG / Twitter / canonical centralisés
│   └── schema/            # organization.html.twig, webpage.html.twig, breadcrumb.html.twig…
├── components/            # TOUS les composants Twig, PascalCase
│   ├── Button.html.twig, Alert.html.twig, Input.html.twig…   # UI kit
│   ├── Layout/            # Header.html.twig, Footer.html.twig, MobileMenu…
│   ├── Section/           # sections de page réutilisables (Hero, CtaFooter…)
│   └── Contact/           # composants par bounded context (ContactSuccess…)
├── public/                # une vue = une route, snake_case
│   ├── base.html.twig     # layout public
│   ├── home/index.html.twig
│   ├── contact/index.html.twig + form.stream.html.twig + success.stream.html.twig
│   └── legal/legal_notice.html.twig, privacy_policy.html.twig, terms_conditions.html.twig
├── emails/                # contact_admin.html.twig, contact_client.html.twig
└── bundles/TwigBundle/Exception/   # error403/404/500 custom avant la prod
```

### Règles Twig

- **Nommage** : composants et leurs dossiers en **PascalCase**, pages en **snake_case**, partials techniques préfixés `_`.
- Préférer **`<twig:Component>`** à `{% include %}` ; `{% include %}` réservé aux partials techniques de `_partials/`.
- Composants **anonymes** (pas de classe PHP) par défaut ; LiveComponent seulement si un vrai besoin d'interactivité serveur apparaît (l'installer à ce moment-là).
- Documenter l'API d'un composant en tête de fichier : commentaire `{# @prop ... #}` + `{% props %}`.
- **`path()` partout** — jamais d'URL en dur. **`asset()`** pour tous les médias.
- **Toute string visible passe par `|trans`** (voir §7) ; `|trans|raw` uniquement quand la clé contient du HTML maîtrisé.
- `base.html.twig` expose au minimum les blocs : `title`, `description`, `url`, `meta_robots`, `og_image`, `schema_page`, `stylesheets`, `javascripts`. Chaque page publique **doit** définir `title`, `description`, `url`.

---

## 4. Formulaires — Turbo obligatoire

**Toute soumission de formulaire public passe par Turbo.** Jamais de `data-turbo="false"`.

Pattern de référence (à appliquer au formulaire de contact et à tout futur formulaire) :

1. Le formulaire est rendu dans une région identifiée (`id="contact-form"`).
2. Au POST, le controller détecte le format Turbo :
   ```php
   if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
       $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
       return $this->render('public/contact/form.stream.html.twig', [...]);
   }
   ```
3. **Succès ET erreurs reviennent en Turbo Stream sur la réponse du POST** : `<turbo-stream action="replace" target="contact-form">` remplace la région en place.
   - `form.stream.html.twig` → formulaire ré-affiché avec les erreurs.
   - `success.stream.html.twig` → bloc de confirmation, extrait dans un composant dédié (ex. `<twig:Contact:Success />`) réutilisé par le fallback.
4. **Fallback no-JS = PRG** : flash + `redirectToRoute()`. **Jamais de message de confirmation rendu sur un GET cacheable** — la réponse GET de confirmation est marquée `no-store`. **[o2switch]** ajouter aussi `X-LiteSpeed-Cache-Control: no-cache` (LiteSpeed épingle sinon la confirmation au refresh).
5. **[o2switch] Les erreurs de validation en stream sortent en statut 200** (pas 422) : LiteSpeed intercepte les 4xx sur mutualisé. Les tests acceptent `[200, 422]`.

Squelette complet d'un formulaire public : **rate limit → honeypot → validation → persist → dispatch email(s) via Messenger (sync) → réponse Turbo Stream / PRG**.

- **Rate limiting** : un limiter par formulaire (`form_contact: 5/min`), stockage `cache.app` (filesystem — **[o2switch]** pas de Redis). Relevé à 1000 en env test.
- **Honeypot** : champ caché non mappé ; si rempli → réponse succès silencieuse (ne pas révéler la détection).
- **Emails** : `TemplatedEmail` + `htmlTemplate('emails/...')`, envoyés dans un MessageHandler sync. Échecs `TransportExceptionInterface` catchés et loggés — **jamais de crash utilisateur si l'email échoue**. Deux emails pour le contact : notification admin + accusé de réception client.
- **Tests obligatoires pour chaque formulaire** : stream succès, stream invalide, honeypot, rate limit.

---

## 5. Assets, Stimulus, Tailwind

### Stimulus
- Un controller par fichier dans `assets/controllers/`, nommage `<name>_controller.js`.
- **Première ligne obligatoire** : `/* stimulusFetch: 'lazy' */` (sauf besoin critique above-the-fold justifié).
- `static targets` / `static values` / `static classes` — **jamais de `querySelector` direct**.
- Préférer `data-action` dans le HTML à `addEventListener` manuel.
- Tout observer/timer/listener créé dans `connect()` est nettoyé dans `disconnect()` (Turbo Drive re-rend les pages : les fuites se voient vite).
- **Interdits : Alpine.js, jQuery, Bootstrap JS.**

### Tailwind v4
- Utilitaires Tailwind d'abord ; CSS custom en dernier recours dans `app.css` (via `@theme` pour les tokens : couleur de marque, breakpoints custom).
- **Tout effet `hover:` / `focus:` / `group-hover:` doit avoir une `transition-*` avec `duration-*`** — y compris sur chaque élément enfant qui change.
- Rebuild : `php bin/console tailwind:build` (watch automatique via `symfony serve` si configuré dans `.symfony.local.yaml`). Si le CSS semble stale : supprimer `public/assets/` puis rebuild.
- Prod : `make tailwind` (build `--minify` + `asset-map:compile` + cache warmup). **[o2switch]** la cible Makefile gère le `TMPDIR` et rappelle le `chmod 711 .`.

---

## 6. SEO & performance (non-négociable)

- Chaque page publique définit `block title`, `block description`, `block url` (canonical). Longueurs : title ≤ 60 caractères, description 120–160.
- JSON-LD via les partials `_partials/schema/` : `Organization` global (la holding) + un node par page (`WebPage`, `ContactPage`, `AboutPage`), injectés en `@graph` dans le layout.
- Images : toujours `width`/`height`/`alt` (l'`alt` via clé i18n) + `loading="lazy"` (`eager` uniquement above-the-fold).
- Liens externes : `rel="noopener noreferrer" target="_blank"`.
- Fonts auto-hébergées avec `font-display: swap` ; preload des assets critiques.
- Pas de hreflang tant que le site est monolingue.
- `robots.txt` + sitemap.xml (presta/sitemap) avant la mise en prod ; les pages légales en priorité basse (0.3).
- LCP : Stimulus lazy + Turbo Drive suffisent — pas de JS non-lazy dans le bundle initial.

---

## 7. i18n

- **Locale unique : `en`** (`default_locale: en`). Pas de préfixe `/{_locale}` dans les routes tant qu'une seconde langue n'existe pas.
- **Toute string visible passe quand même par `|trans`** avec des clés dans `translations/messages.en.yaml` — c'est ce qui rendra l'ajout d'une locale trivial.
- Clés en **notation pointée nested** : `home.hero.title`, `contact.form.submit`, `legal.privacy.title`.
- Messages de validation dans `validators.en.yaml`.
- **Jamais d'em-dash `—`** dans les textes visibles : utiliser `:`, `,`, `(...)` ou deux phrases.
- Jamais de texte en dur dans un template ou un controller.

---

## 8. Sécurité (non-négociable)

Pas d'espace membre ni d'admin pour l'instant — le périmètre sécurité se concentre sur les formulaires publics et les headers :

- **CSRF activé partout**, jamais désactivé (le controller Stimulus `csrf_protection_controller.js` est en place).
- Toute entrée utilisateur passe par un **FormType + Validator** (ou DTO `#[MapRequestPayload]`). Jamais de lecture brute de `$request` injectée en DB/email.
- Auto-escape Twig ; `|raw` uniquement sur du `|trans` HTML maîtrisé.
- SQL uniquement via DQL/QueryBuilder paramétré.
- **Rate limiting** sur tous les POST publics (storage `cache.app`).
- Headers via **nelmio/security-bundle** avant la prod : HSTS (prod only), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, Referrer-Policy, Permissions-Policy, CSP (commencer en report-only, objectif : pas d'`unsafe-inline` sur `script-src`).
- Cookies session : `cookie_secure: auto`, `samesite: lax`, `httponly: true`.
- **Secrets** : jamais en dur ni committés — `.env.local` en dev, vault Symfony (`config/secrets/prod/`) en prod. Lockfiles committés.
- Jamais `eval()` ni `unserialize()` sur données non maîtrisées.
- Monolog : jamais de PII ni de contenu de message loggé en clair ; `debug: false` en prod.
- Si un jour un espace authentifié apparaît : `#[IsGranted]` + Voters (jamais de check inline), hasher `auto`, login throttling natif, reset via `symfonycasts/reset-password-bundle`.

---

## 9. Tests (discipline non-négociable)

- **La suite est toujours verte sur `main`.** Zéro régression tolérée ; baseline lancée avant tout refactoring > 50 lignes.
- Nouveau code = nouveau test. Bug fix = test de non-régression. Tests mis à jour **dans le même commit** que le code.
- PHPUnit 13, structure `tests/` **miroir de `src/`** par contexte (`tests/Contact/`, `tests/Shared/`) + `tests/Controller/` (fonctionnels `WebTestCase`) + `tests/Flow/` (parcours bout-en-bout, ex. `ContactFlowTest`).
- Tests fonctionnels sur **vraie DB de test** (jamais de mock Doctrine) + fixtures/factories Foundry. Init : `make test-db-init` / reset : `make test-db-reset`.
- Messenger **in-memory** en env test (`config/packages/test/messenger.yaml`) — permet d'asserter les messages dispatchés.
- Rate limiters relevés (1000) et hashers au coût minimal en env test.
- Soumission invalide : asserter `in_array($statusCode, [200, 422])` **[o2switch]**.
- Nommage par comportement : `testItRejectsContactSubmissionWithInvalidEmail()`.
- Chemins critiques à couvrir en priorité : flux contact complet (affichage, succès stream, erreurs stream, honeypot, rate limit, emails dispatchés), rendu des pages légales, SEO (title/description présents et aux bonnes longueurs).

---

## 10. Tooling & workflow

### Makefile (cibles existantes)
| Cible | Rôle |
|---|---|
| `make start` | `symfony server:start --no-tls` |
| `make lint` | lint:twig + lint:yaml + lint:container + PHPStan + php-cs-fixer dry-run |
| `make cs-fix` | php-cs-fixer fix |
| `make test` | PHPUnit |
| `make test-prod` | les 6 étapes de validation pré-déploiement |
| `make test-db-init` / `test-db-reset` | DB de test + migrations |
| `make tailwind` | build minifié + asset-map:compile + warmup prod |
| `make deploy` | **[o2switch]** git pull ff-only + composer.phar install --no-dev + migrations + asset-map:compile + cache prod |

La base de test est `veylam_test` (suffixe Doctrine `_test` sur la base `veylam` de `.env.local`), créée via `make test-db-init`.

- **PHPStan niveau 5** (+ baseline `phpstan-baseline.neon` pour les faux positifs du boilerplate) (+ baseline si dette), paths `src` + `tests`, extensions Doctrine + Symfony.
- **PHP-CS-Fixer** : `@Symfony` + `@PHP82Migration`, exclure `var/`, `vendor/`, `config/bundles.php`, `config/reference.php`.
- **DB dev** : MySQL via MAMP (pas de conteneur DB utilisé) ; Mailpit à ajouter dans `compose.override.yaml` pour tester les emails localement (ports 1025/8025).
- Pas de CI : la validation est `make test-prod` avant chaque `make deploy`.
- **Toujours lancer `make lint && make test` avant de considérer une tâche terminée.**

---

## 11. À ne JAMAIS faire

- Router un message Messenger en **async** ou supposer qu'un worker tourne **[o2switch]**.
- Introduire **npm, Webpack, Vite, Node, Alpine.js, jQuery, Bootstrap**.
- Hardcoder une URL (`path()` !), une string visible (`|trans` !), un secret.
- Passer une **entité Doctrine à un template** (DTO obligatoire).
- Soumettre un formulaire public **hors Turbo** (`data-turbo="false"` interdit).
- Mocker la DB dans les tests fonctionnels.
- Laisser un `console.log` / `dump()` / `dd()` dans un commit.
- Créer des fichiers `.md` de documentation sans demande explicite.
- **Committer ou pousser sans demande explicite.**
- Utiliser un em-dash `—` dans un texte visible du site.

---

## 12. Préférences générales

- Pratiques Symfony **dernière version** (doc officielle > habitudes) : attributs, readonly, enums, FormFlow natif si un formulaire multi-étapes apparaît.
- Architecture clean et scalable : bounded contexts, DTOs, services injectés, nommage strict.
- SEO et performance de premier ordre sur tout ce qui est public.
- Réponses concises en français ; code, commits et contenu du site en anglais.
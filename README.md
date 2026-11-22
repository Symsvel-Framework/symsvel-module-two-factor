# SymSvel TwoFactor (module natif de sécurité)

> [!IMPORTANT]
> **Vous souhaitez utiliser SymSvel ? Rendez-vous sur [SymSvel CLI](https://github.com/Symsvel-Framework/symsvel-cli).** Ce dépôt est un **composant du framework**, pas un point d'installation autonome. Création de projets, installation des modules et mises à jour passent par la CLI. Les éventuelles commandes Composer, npm ou Symfony décrites ci-dessous concernent le travail des mainteneurs, sauf indication explicite contraire.

> [!WARNING]
> **SymSvel est en cours de développement.** Les fonctionnalités et procédures peuvent évoluer ou présenter des anomalies. **Utilisation à vos risques et périls.**

Dépôt GitHub : `symsvel-module-two-factor`. **Package Composer : `symsvel-framework/module-two-factor`**. Le dépôt, le package Composer et le manifeste `TwoFactor` suivent désormais une convention cohérente ; le slug `module-two-factor` respecte le contrat Tooling.

Ce module ajoute une authentification à deux facteurs TOTP sans SchebTwoFactorBundle, en adaptant les classes XCore : `TotpService`, `SecretCipher`, `RecoveryCodeService` et le mécanisme de challenge temporaire.

## Périmètre

- Installé automatiquement avec les modules natifs ; dépend d'Auth et Settings. BO et App restent des contextes indépendants.
- TOTP RFC 6238 SHA1, 6 chiffres, pas de 30 s, fenêtre ±1 pas.
- Clé TOTP chiffrée par libsodium ; codes de secours hachés et consommables une seule fois.
- Challenge aléatoire opaque 256 bits, hash SHA-256 en BDD, durée 5 min et 5 tentatives maximum.
- Rejeu d'un même TOTP interdit par compte et par fenêtre temporelle.
- Le module stocke ses deux tables, sans entité métier dans Back/Core.
- L'identité est toujours validée par Auth et les tokens sont exclusivement émis par Auth, dans la transaction qui consomme le challenge.

## Configuration

La 2FA est **toujours obligatoire pour toute nouvelle session BO**, y compris celle du premier administrateur créé au Setup. Aucun interrupteur BO ne peut désactiver cette obligation. L'ancien paramètre `two-factor.bo_required`, s'il existe dans une installation, est ignoré et n'est plus exposé dans Settings.

Le paramètre `two-factor.app_required` permet d'imposer la 2FA côté App (désactivé par défaut). Sans politique App et sans compte déjà enrôlé, la connexion App demeure classique. Un compte App déjà enrôlé doit continuer à vérifier son second facteur, même si la politique globale est désactivée.

La page optionnelle `/two-factor` est synchronisée dans BO et App via Tooling lors de l'installation du module. Lors du premier challenge, le module génère **localement un QR code** avec la bibliothèque `qrcode`, déjà utilisée dans XCore, à partir de `provisioning_uri`. La clé d'enrôlement reste disponible dans un volet de configuration manuelle si le QR code ne peut pas être affiché. Après validation, huit codes de secours apparaissent une seule fois. Aucun service externe ne reçoit la clé TOTP.

Les écrans et les clients BO/App hôtes utilisent une réponse de challenge HTTP 202 sans stocker d'accès ou de rafraîchissement avant validation. Côté BO, le refresh reste dans le cookie HttpOnly géré par le contrôleur Auth existant.

## État et validation

Le code et les tests automatisés sont sur `dev`. Vérifier la CI du dernier commit ; puis effectuer un crash-test local, la synchronisation du module Svelte, les migrations Schema multibase, l'enrôlement BO obligatoire (y compris premier admin), l'App facultative, les codes de récupération, les expirations et le refus de connexion BO si le provider est absent. La livraison ne sera déclarée terminée qu'après CI verte, CT validés et documentation à jour.

Voir [documentation technique](docs/dev/index.md).

### Dépendances frontend du QR code (18/11/2026)

Le module possède ses pages BO et App. Les builds Svelte sont réalisés dans
les composants hôtes ; `qrcode@^1.5.4` (runtime) et
`@types/qrcode@^1.5.5` (TypeScript) sont donc déclarés dans les dépôts
`symsvel-bo` et `symsvel-app`, **pas dans Core ni Back**. Les fichiers
`package-lock.json` des hôtes suivent ces dépendances. Sur un projet
existant, mettre à jour BO et App (si présente) avec `symsvel update bo`
et `symsvel update app` avant de synchroniser la version du module.
La CI assemble les pages dans chaque hôte et doit vérifier que le QR
produit est un vrai PNG local. CT utilisateur indispensable.

## Distribution stable v1.0.0 (20/11/2026)

Le manifeste Composer du tag stable déclare exclusivement des contraintes `^1.0` pour ses dépendances SymSvel : `symsvel-framework/module-auth`, `symsvel-framework/module-settings`, `symsvel-framework/module-acls`, `symsvel-framework/module-mails`. Les références `dev-dev` restent réservées à la branche `dev` et à l'installation de maintenance avec `--dev`. Le tag `v1.0.0` n'est à publier **qu'après** le tag stable de chaque dépendance, son indexation dans Satis et la réussite des contrôles CI ; aucune release n'est produite par ce commit.

---

## Intégration continue GitHub-hosted (22/11/2026)

La QA active de ce dépôt public (`.github/workflows/qa.yml`) utilise **GitHub-hosted Ubuntu 24.04** , et non les runners Docker du mainteneur. Les tests PHP 8.4, BO SvelteKit et App SvelteKit utilisent Node.js 24 et Chromium avec ses dépendances.

Les runners locaux sont réservés aux dépôts privés `symsvel-packages` et `symsvel-recipes`. Les crash-tests restent distincts de la CI.

## Licence MIT et contributions

Ce dépôt est distribué sous [licence MIT](LICENSE). Le code peut être utilisé, modifié et redistribué, y compris dans des projets commerciaux ou propriétaires, dans les conditions de cette licence (notamment la conservation de la notice de copyright et de licence).

**Contribuer :** créer un fork, puis proposer une pull request vers `dev` lorsque cette branche existe, sinon vers la branche par défaut. Expliquer la modification et fournir les tests et la documentation concernés. Les mainteneurs conservent la maîtrise des revues, des validations (CI et crash-tests), de l'intégration et des releases officielles. Une PR ne garantit pas son acceptation.

**Versions officielles :** seules les versions diffusées par l'organisation [Symsvel-Framework](https://github.com/Symsvel-Framework) sont officielles. Les forks sont libres sous MIT, mais ne doivent pas être présentés comme officiellement approuvés par les mainteneurs. Cette distinction n'ajoute aucune restriction aux droits accordés par la licence MIT sur le code.

## Participer et soutenir le projet

SymSvel est un projet en cours de développement. Pour contribuer, proposer une amélioration ou échanger avec le mainteneur : **[xavier@xavier-tezza.fr](mailto:xavier@xavier-tezza.fr)**.

SymSvel vous est utile et vous souhaitez me remercier ? **Offrez-moi une bière ! 🍺** (lien PayPal à compléter avant l'ouverture publique).

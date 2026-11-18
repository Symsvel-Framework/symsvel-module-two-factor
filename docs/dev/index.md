# TwoFactor : contrat technique et sécurité

## Provenance et architecture

L'implémentation reprend directement de XCore (`xcore-legacy-back`) les classes `TotpService`, `SecretCipher`, `RecoveryCodeService` et le principe d'un `LoginChallengeManager`. Les algorithmes restent natifs : TOTP RFC 6238/SHA1, libsodium `secretbox`, `password_hash` pour les codes de secours, `random_bytes` pour les challenges. **Aucune dépendance SchebTwoFactorBundle**.

Le dépôt GitHub est `symsvel-module-two-factor`, le package Composer `symsvel-framework/module-two-factor` et le manifeste porte `name: TwoFactor`. Cette convention respecte les contrats `ComposerModuleRegistry` et `SettingKeyQualifier`, dont les slugs de module doivent commencer par une lettre. Aucun ajustement de Tooling n'est nécessaire.

Le module TwoFactor fait partie de la distribution standard et de ses dépendances natives ; BO impose toujours un second facteur et App conserve une politique configurable. L'ancien setting `two-factor.bo_required` est désormais ignoré et absent du registre Settings ; seul `two-factor.app_required` est configurable. Un compte App déjà enrôlé ne doit jamais contourner son second facteur.

Auth enregistre les providers avec `symsvel.auth.second_factor` dans `PasswordLogin`. Le contrat `LoginSecondFactorInterface` déclare `begin(TokenSubject, now)` et `complete(context, challenge, code, now)`. Le module est le seul fournisseur de ce tag ; plusieurs fournisseurs sont refusés explicitement. Auth ne dépend d'aucune classe concrète TwoFactor. Sans provider, la connexion App historique est inchangée, mais une connexion BO doit échouer sans émission de tokens (fail closed).

La réponse d'Auth après mot de passe valide est soit la session normale (HTTP 200), soit `SecondFactorChallenge` (HTTP 202) avec un challenge éphémère, sa date d'expiration et, en cas de première activation uniquement, une clé TOTP et une URI `otpauth://`. Auth ne délivre **aucun** token d'accès ou refresh avant validation. L'API `POST /apiopen/auth/second-factor` traite l'App ; `POST /apibo/auth/browser/second-factor` traite le BO et retourne l'accès en posant le refresh en cookie HttpOnly. Le contrôleur BO historique `/apibo/auth/login` supporte les challenges, mais les interfaces BO emploient la session navigateur.

L'émission passe par `TokenIssuer::issueWhen`, afin que la preuve 2FA soit contrôlée **dans la même transaction** que l'émission de la paire Auth. Ceci évite les transactions imbriquées explicitement interdites par `DbalTokenStore` sous SQLite. Le garde doit valider la politique, le principal actif, le challenge non consommé et non expiré avant de retourner true ; un retour false valide les incréments d'essais, sans émettre de tokens. Les autres mécanismes futurs (notamment Google/OIDC) doivent emprunter ce même contrôle pré-session, jamais appeler directement `TokenIssuer::issue` en court-circuitant la politique 2FA.

## Schéma et sécurité

Migration module `Version20200101000900`, au format Doctrine Schema :
- `symsvel_2fa_accounts` : contexte `bo` ou `app` et identifiant du principal, secret TOTP chiffré, état d'activation, compteur TOTP utilisé et hashes des codes de secours ;
- `symsvel_2fa_challenges` : empreinte du challenge aléatoire, contexte, principal, création/expiration, consommation et nombre de tentatives.

Le couple `(context, principal_id)` est unique. Le challenge expire après cinq minutes, est limité à cinq tentatives, consommé une seule fois ; le même code TOTP ne peut être réutilisé pour émettre une autre session pendant sa fenêtre. Les modifications des comptes, la consommation du challenge et l'émission de tokens partagent la même transaction de la connexion DBAL du Back. Les codes de secours sont des secrets à usage unique ; leur valeur n'est renvoyée qu'une fois après l'activation. La clé de chiffrement est dérivée de `kernel.secret` ; sa rotation nécessite une procédure de réenrôlement ou de re-chiffrement, non implémentée ici, et **ne doit pas se faire silencieusement**.

La 2FA n'est pas un facteur supplémentaire pour le rafraîchissement d'une même session valide. Une future connexion externe OIDC devra passer par l'émission interactive soumise au challenge. La protection contre la force brute initiale sur le mot de passe demeure du ressort d'Auth ; la limitation par challenge appartient à TwoFactor.

## Frontend et limites

Les deux parcours `/two-factor` sont fournis par le module et synchronisés au build statique Svelte par Tooling. Lors de la première activation, les pages génèrent un QR code **local** à partir de l'URI `otpauth://` fournie par Auth avec `qrcode`, à la manière d'XCore. La clé TOTP manuelle est disponible dans un volet de secours et s'ouvre automatiquement en cas d'échec du QR. Les pages demandent ensuite un code TOTP ou un code de secours, puis affichent les codes de récupération après activation. Aucun refresh BO n'est exposé en JavaScript. La saisie est réinitialisée après validation et le BO conserve son challenge temporaire dans `sessionStorage` par onglet (rechargement autorisé), alors que l'App ne le conserve encore qu'en mémoire JavaScript (rechargement exigeant une nouvelle connexion).

**Limites à traiter avant une release stable :** finaliser la personnalisation de l'interface et les traductions i18n via Languages (les textes de cette première version sont provisoires), parcours d'enrôlement volontaire pour les comptes lorsque la politique n'est pas obligatoire, parcours administratif de réinitialisation TOTP avec vérification renforcée, nettoyage des challenges expirés, stratégie de rotation du secret de chiffrement, tests de contention multi-SGBD et de restauration après interruption. Ces sujets ne doivent pas être masqués par une CI verte.

## Validation

Tests unitaires : vecteurs RFC 6238, Base32 strict, codes de secours, chiffrement Sodium, enregistrement des services et indépendance des paramètres. Test d'intégration : véritable SQLite + `SettingManager` + `DbalTokenStore`, sans serveur réseau, pour contrôler l'émission conditionnelle, le rejeu, l'expiration, le contexte, les codes de récupération et le verrouillage après cinq tentatives erronées. Un nouveau challenge doit rester utilisable après l'épuisement du précédent.

Les tests sur SQLite ne remplacent pas les CT sur PostgreSQL, MySQL et MariaDB. Le projet BO seul ne doit pas exiger Users ; App ne doit pas être supposée présente. Toute installation via CLI nécessite publication de la recette Flex et résolution Composer sur Satis. La recette du module ne suffit pas si elle n'est pas ajoutée au catalogue technique.

## Test navigateur d'assemblage BO avec challenge HTTP 202 (18/11/2026)

La CI `bo-frontend` ne se limite plus au lint et au build statique :
le test `tests/bo-second-factor.e2e.ts` est copié dans le BO réellement
assemblé avec la page TwoFactor fournie par le module, puis exécuté via
Playwright Chromium. Avec des réponses API simulées (aucun mot de passe réel),
il vérifie la navigation `/login → HTTP 202 → /two-factor → /dashboard`,
le retour de `/two-factor` vers `/login` sans challenge, la conservation du
challenge lors d'un rechargement, son effacement après validation et l'absence
d'appel prématuré à `/apibo/auth/browser/refresh`.

Ce test vérifie le routage et le cycle navigateur de l'assemblage, mais ne
prouve ni le déploiement des fichiers sur le projet XT ni l'intégration avec
le véritable backend et ses cookies. Si le CT local échoue alors que ce test
passe, comparer la route Svelte synchronisée, le build servi et le contenu
non sensible de la réponse 202 avant de modifier Auth.

## QR code d'enrôlement local (18/11/2026)

Le QR est généré au montage de la route seulement si le serveur renvoie
`enrollment_required=true` et une URI `otpauth://totp/`. Les deux pages
Svelte utilisent `QRCode.toDataURL(uri, { errorCorrectionLevel: 'M', margin: 2,
width: 240 })` pour produire un PNG en mémoire sans aucune requête à un
service QR distant. L'image n'est pas conservée après fermeture de la page.
En cas d'URI absente/invalide ou d'échec de génération, la clé manuelle
reste disponible et son volet est ouvert. Le QR n'est jamais affiché pour
une connexion à un compte 2FA déjà activé.

Les bibliothèques `qrcode@^1.5.4` et `@types/qrcode@^1.5.5` sont
maintenues dans les hôtes BO/App et leur lock npm, car les modules Svelte
sont synchronisés dans ces hôtes et ne disposent pas d'un build autonome.
Le module reste responsable de ses écrans, sans bibliothèque PHP de 2FA
ajoutée ni changement à Auth. Mettre à jour les composants natifs avant la
synchronisation du module, puis construire les deux interfaces. Les tests
navigateur d'assemblage doivent confirmer un PNG local affiché ; les CT
utilisateur doivent valider le scan sur un authenticator réel.

### Tests navigateur du QR d'enrôlement (18/11/2026)

L'assemblage BO teste désormais la présence d'un véritable PNG `data:`
avec largeur native non nulle, sa régénération après rechargement, le volet
manuel masqué par défaut et son ouverture en cas d'URI QR invalide. L'assemblage
App exécute également un test navigateur de la route 2FA avec challenge 202,
PNG généré et clé manuelle accessible. Ces tests ne valident pas le scan par
un authenticator réel ; celui-ci reste à effectuer au crash-test utilisateur.


## Traductions 2FA (18/11/2026)

Le module TwoFactor possède ses libellés BO et App, dans `bo/src/lib/translations/<lang>.ts` et `app/src/lib/translations/<lang>.ts`. Les chemins canoniques sont `bo.two-factor.<clé>` et `app.two-factor.<clé>` ; Tooling copie les sources dans les hôtes et Languages persiste les traductions par défaut après migrations. Le QR code reste généré localement, la configuration manuelle reste disponible. CI + CT XT exigés.

### QA des dictionnaires 2FA

La CI d'intégration assemble désormais les dictionnaires FR/EN BO et App à la même destination que Tooling : `src/lib/translations/two-factor`. Les écrans du second facteur sont compilés avec les clés `bo.two-factor.*` et `app.two-factor.*` et les tests E2E existants sont rejoués. Le déclencheur de branche de travail est temporaire : il sera retiré après validation, sans toucher aux branches `dev` et `main`.


### Validation CI de la branche de traduction (18/11/2026)

La CI complète de `feat/translations-sources` a terminé avec succès sur les runners locaux. Le déclencheur temporaire de cette branche a été retiré du workflow, en conservant les déclencheurs habituels `dev`, `main` et PR. Les tests d'installation et crash-tests XT restent indispensables avant le merge.

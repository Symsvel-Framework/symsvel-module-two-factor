# TwoFactor : contrat technique et sécurité

## Provenance et architecture

L'implémentation reprend directement de XCore (`xcore-legacy-back`) les classes `TotpService`, `SecretCipher`, `RecoveryCodeService` et le principe d'un `LoginChallengeManager`. Les algorithmes restent natifs : TOTP RFC 6238/SHA1, libsodium `secretbox`, `password_hash` pour les codes de secours, `random_bytes` pour les challenges. **Aucune dépendance SchebTwoFactorBundle**.

Le dépôt porte le nom `symsvel-module-2fa` mais Composer expose `module-two-factor`, car les contrats `ComposerModuleRegistry` et `SettingKeyQualifier` n'acceptent pas de slug commençant par un chiffre. Le manifeste porte `name: TwoFactor`. Ne pas modifier Tooling pour contourner ce contrat : le changement de package est volontaire.

Auth enregistre les providers avec `symsvel.auth.second_factor` dans `PasswordLogin`. Le contrat `LoginSecondFactorInterface` déclare `begin(TokenSubject, now)` et `complete(context, challenge, code, now)`. Le module est le seul fournisseur de ce tag ; plusieurs fournisseurs sont refusés explicitement. Auth ne dépend jamais de TwoFactor. Sans ce service facultatif, login classique et émission des tokens sont inchangés.

La réponse d'Auth après mot de passe valide est soit la session normale (HTTP 200), soit `SecondFactorChallenge` (HTTP 202) avec un challenge éphémère, sa date d'expiration et, en cas de première activation uniquement, une clé TOTP et une URI `otpauth://`. Auth ne délivre **aucun** token d'accès ou refresh avant validation. L'API `POST /apiopen/auth/second-factor` traite l'App ; `POST /apibo/auth/browser/second-factor` traite le BO et retourne l'accès en posant le refresh en cookie HttpOnly. Le contrôleur BO historique `/apibo/auth/login` supporte les challenges, mais les interfaces BO emploient la session navigateur.

L'émission passe par `TokenIssuer::issueWhen`, afin que la preuve 2FA soit contrôlée **dans la même transaction** que l'émission de la paire Auth. Ceci évite les transactions imbriquées explicitement interdites par `DbalTokenStore` sous SQLite. Le garde doit valider la politique, le principal actif, le challenge non consommé et non expiré avant de retourner true ; un retour false valide les incréments d'essais, sans émettre de tokens. Les autres mécanismes futurs (notamment Google/OIDC) doivent emprunter ce même contrôle pré-session, jamais appeler directement `TokenIssuer::issue` en court-circuitant la politique 2FA.

## Schéma et sécurité

Migration module `Version20200101000900`, au format Doctrine Schema :
- `symsvel_2fa_accounts` : contexte `bo` ou `app` et identifiant du principal, secret TOTP chiffré, état d'activation, compteur TOTP utilisé et hashes des codes de secours ;
- `symsvel_2fa_challenges` : empreinte du challenge aléatoire, contexte, principal, création/expiration, consommation et nombre de tentatives.

Le couple `(context, principal_id)` est unique. Le challenge expire après cinq minutes, est limité à cinq tentatives, consommé une seule fois ; le même code TOTP ne peut être réutilisé pour émettre une autre session pendant sa fenêtre. Les modifications des comptes, la consommation du challenge et l'émission de tokens partagent la même transaction de la connexion DBAL du Back. Les codes de secours sont des secrets à usage unique ; leur valeur n'est renvoyée qu'une fois après l'activation. La clé de chiffrement est dérivée de `kernel.secret` ; sa rotation nécessite une procédure de réenrôlement ou de re-chiffrement, non implémentée ici, et **ne doit pas se faire silencieusement**.

La 2FA n'est pas un facteur supplémentaire pour le rafraîchissement d'une même session valide. Une future connexion externe OIDC devra passer par l'émission interactive soumise au challenge. La protection contre la force brute initiale sur le mot de passe demeure du ressort d'Auth ; la limitation par challenge appartient à TwoFactor.

## Frontend et limites

Les deux parcours `/two-factor` sont fournis par le module et synchronisés au build statique Svelte par Tooling. Les pages demandent une clé TOTP manuelle à la première activation, un code TOTP ou un code de secours, puis affichent les codes de récupération après activation. Aucun refresh BO n'est exposé en JavaScript. La saisie est réinitialisée après validation et le challenge n'est gardé qu'en mémoire d'onglet ; un rechargement de la page nécessite une nouvelle connexion.

**Limites à traiter avant une release stable :** finaliser la personnalisation de l'interface et les traductions i18n via Languages (les textes de cette première version sont provisoires), QR code local facultatif, parcours d'enrôlement volontaire pour les comptes lorsque la politique n'est pas obligatoire, parcours administratif de réinitialisation TOTP avec vérification renforcée, nettoyage des challenges expirés, stratégie de rotation du secret de chiffrement, tests de contention multi-SGBD et de restauration après interruption. Ces sujets ne doivent pas être masqués par une CI verte.

## Validation

Tests unitaires : vecteurs RFC 6238, Base32 strict, codes de secours, chiffrement Sodium, enregistrement des services et indépendance des paramètres. Test d'intégration : véritable SQLite + `SettingManager` + `DbalTokenStore`, sans serveur réseau, pour contrôler l'émission conditionnelle, le rejeu, l'expiration, le contexte et les codes de récupération.

Les tests sur SQLite ne remplacent pas les CT sur PostgreSQL, MySQL et MariaDB. Le projet BO seul ne doit pas exiger Users ; App ne doit pas être supposée présente. Toute installation via CLI nécessite publication de la recette Flex et résolution Composer sur Satis. La recette du module ne suffit pas si elle n'est pas ajoutée au catalogue technique.

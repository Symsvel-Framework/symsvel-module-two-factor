# SymSvel TwoFactor (module optionnel)

Dépôt GitHub : `symsvel-module-two-factor`. **Package Composer : `symsvel-framework/module-two-factor`**. Le dépôt, le package Composer et le manifeste `TwoFactor` suivent désormais une convention cohérente ; le slug `module-two-factor` respecte le contrat Tooling.

Ce module ajoute une authentification à deux facteurs TOTP sans SchebTwoFactorBundle, en adaptant les classes XCore : `TotpService`, `SecretCipher`, `RecoveryCodeService` et le mécanisme de challenge temporaire.

## Périmètre

- Installation facultative, dépend d'Auth et Settings ; BO et App restent indépendants.
- TOTP RFC 6238 SHA1, 6 chiffres, pas de 30 s, fenêtre ±1 pas.
- Clé TOTP chiffrée par libsodium ; codes de secours hachés et consommables une seule fois.
- Challenge aléatoire opaque 256 bits, hash SHA-256 en BDD, durée 5 min et 5 tentatives maximum.
- Rejeu d'un même TOTP interdit par compte et par fenêtre temporelle.
- Le module stocke ses deux tables, sans entité métier dans Back/Core.
- L'identité est toujours validée par Auth et les tokens sont exclusivement émis par Auth, dans la transaction qui consomme le challenge.

## Configuration

Dans le BO Settings, les paramètres du module sont `two-factor.bo_required` et `two-factor.app_required`, booléens indépendants et non bloquants pendant l'installation. Sans paramètre activé et sans 2FA déjà enrôlée, la connexion historique continue. Une politique activée impose un enrôlement lors de la prochaine connexion.

La page optionnelle `/two-factor` est synchronisée dans BO et App via Tooling lors de l'installation du module. Lors du premier challenge, la clé d'enrôlement est affichée pour une configuration **manuelle** dans une application TOTP. Après validation, huit codes de secours apparaissent une seule fois. Le QR code XCore n'est pas encore repris : aucune bibliothèque frontend n'est ajoutée arbitrairement.

Les écrans et les clients BO/App hôtes utilisent une réponse de challenge HTTP 202 sans stocker d'accès ou de rafraîchissement avant validation. Côté BO, le refresh reste dans le cookie HttpOnly géré par le contrôleur Auth existant.

## État et validation

Le code et les tests automatisés sont sur `dev`. Vérifier la CI du dernier commit ; puis effectuer un crash-test local, la synchronisation du module Svelte, les migrations Schema multibase, l'enrôlement BO, l'App, les codes de récupération, les expirations et le parcours avec 2FA désinstallée. La livraison ne sera déclarée terminée qu'après CI verte, CT validés et documentation à jour.

Voir [documentation technique](docs/dev/index.md).

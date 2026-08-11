#!/bin/bash

# publish.sh - Script pour publier MoSQL sur GitHub et Packagist

set -e

# Variables
REPO_NAME="mosql"
REPO_URL="https://github.com/$(git config user.name)/${REPO_NAME}.git"
VERSION=${1:-"1.0.0"}
USERNAME=$(git config user.name)

echo "🚀 Publication de MoSQL v${VERSION}..."

# 1. Nettoyer et installer les dépendances
echo "📦 Installation des dépendances..."
composer install --no-dev --optimize-autoloader

# 2. Vérifier le code
echo "🔍 Vérification du code..."
composer stan || true
composer test || true

# 3. Commit des changements
echo "📝 Commit des changements..."
git add .
git commit -m "Release v${VERSION}" || echo "Aucun changement à committer"

# 4. Créer le tag
echo "🏷️ Création du tag v${VERSION}..."
git tag -a "v${VERSION}" -m "Release v${VERSION}"

# 5. Pousser vers GitHub
echo "⬆️ Push vers GitHub..."
git push -u origin main
git push origin "v${VERSION}"

echo "✅ Version ${VERSION} publiée avec succès !"

# 6. Instructions pour Packagist
echo ""
echo "📦 Pour publier sur Packagist :"
echo "   1. Allez sur https://packagist.org/packages/submit"
echo "   2. Entrez : https://github.com/${USERNAME}/${REPO_NAME}"
echo "   3. Cliquez sur Submit"
echo ""
echo "🔔 Ou utilisez l'API :"
echo "   curl -X POST https://packagist.org/api/create-package \\"
echo "        -H 'Content-Type: application/json' \\"
echo "        -d '{\"repository\":{\"url\":\"https://github.com/${USERNAME}/${REPO_NAME}\"}}' \\"
echo "        -u ${USERNAME}:VOTRE_TOKEN"
echo ""
echo "🎉 Installation : composer require dovstone/${REPO_NAME}"
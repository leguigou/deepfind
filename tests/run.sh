#!/usr/bin/env bash
# Lance la suite de tests complète dans un environnement isolé :
#   base MySQL de test + FAUX Meilisearch (tests/fake-meili.php) + serveur web PHP intégré.
# Prérequis : une base et un utilisateur MySQL de test (voir README, section Tests).
# Variables : TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASS, TEST_DB_HOST (défaut : localhost / deepfind_test / df_test / df_test_pw)
# Ne jamais lancer sur la base de production : les tables de test sont vidées.
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT=$PWD
TMP=$ROOT/tests/_tmp
rm -rf "$TMP"; mkdir -p "$TMP/meili"

cat > "$TMP/.env" <<EOF
APP_ENV=test
APP_URL=http://localhost:8080
APP_LANGS=fr
ADMIN_TOKEN=test-admin-token-0123456789abcdef0123
APP_SECRET=0123456789abcdef0123456789abcdef
DB_HOST=${TEST_DB_HOST:-localhost}
DB_NAME=${TEST_DB_NAME:-deepfind_test}
DB_USER=${TEST_DB_USER:-df_test}
DB_PASS=${TEST_DB_PASS:-df_test_pw}
MEILI_URL=http://127.0.0.1:7799
MEILI_MASTER_KEY=testkey
RATE_STORE=apcu
EOF
export DEEPFIND_ENV_FILE=$TMP/.env FAKE_MEILI_DIR=$TMP/meili

php -r 'require "src/bootstrap.php"; $p = DeepFind\Db::pdo(); foreach ($p->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) { $p->exec("DROP TABLE `$t`"); }'

php -S 127.0.0.1:7799 tests/fake-meili.php > "$TMP/meili.log" 2>&1 & MEILI_PID=$!
php -d apc.enable_cli=1 -S 127.0.0.1:8080 -t public public/index.php > "$TMP/web.log" 2>&1 & WEB_PID=$!
trap 'kill $MEILI_PID $WEB_PID 2>/dev/null || true' EXIT
sleep 1

php bin/console install > /dev/null
php bin/console rules:import --lang=fr --synonyms=samples/synonymes.csv --rules=samples/boosts.csv --redirects=samples/redirections.csv > /dev/null
php bin/console import:run --source=produits_fr
php bin/console import:run --source=blog_fr
php bin/console import:run --source=produits_fr --type=delta
TEST_KEY=$(php bin/console key:create --name=Tests --origins=localhost,example.com)
echo "$TEST_KEY" > "$TMP/key"

TEST_BASE_URL=http://127.0.0.1:8080 TEST_KEY=$TEST_KEY php tests/run.php
if grep -E "Fatal|Warning|Deprecated|Notice" "$TMP/web.log"; then echo "Avertissements PHP détectés (voir ci-dessus)"; exit 1; fi

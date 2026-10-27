#!/usr/bin/env bash
# Publica una nueva versión del plugin: sube el número, anota el cambio, prueba el núcleo y genera el zip.
# Uso (desde la raíz del proyecto):  dev/release.sh 1.2.1 "Qué cambió"
set -euo pipefail
V="${1:?Falta la versión, p. ej. 1.2.1}"; MSG="${2:?Falta la descripción del cambio}"
D="golehighacres-blocks"; MAIN="$D/golehighacres-blocks.php"; DATE="$(date +%F)"
[[ "$V" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Versión no válida: $V"; exit 1; }
sed -i -E "s/^( \* Version: ).*/\1$V/; s/(define\( 'GLH_VER', ')[0-9.]+(' \);)/\1$V\2/" "$MAIN" "$D/core/load.php"
python3 - "$D/CHANGELOG.md" "$V" "$DATE" "$MSG" <<'PY'
import sys
p, v, d, m = sys.argv[1:]
s = open(p, encoding='utf-8').read()
i = s.index('\n## ')
open(p, 'w', encoding='utf-8').write(s[:i] + f'\n## {v} — {d}\n- {m}\n' + s[i:])
PY
sed -i -E "s/(\*\*Versión actual: )[0-9.]+(\*\*)/\1$V\2/" "$D/README.md"
php "$D/tests/core-test.php" > /dev/null || { echo "La prueba del núcleo falló: php $D/tests/core-test.php"; exit 1; }
mkdir -p dist; rm -f "dist/$D-$V.zip"; zip -qr "dist/$D-$V.zip" "$D" -x "*.DS_Store" "$D/node_modules/*"
cp "dist/$D-$V.zip" "dist/$D.zip"
echo "Listo: $V  ->  dist/$D-$V.zip  (copia: dist/$D.zip)"

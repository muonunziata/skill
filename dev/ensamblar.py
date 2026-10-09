"""Une el HTML/CSS que genera harness-veloxforge.php con los recursos del plugin en una página abrible.
Uso: python3 ensamblar.py RUTA_VELOXFORGE RUTA_PLUGIN_GLH CARPETA_OUT [nombre ...]"""
import sys, os
vf, glh, out = sys.argv[1:4]
names = sys.argv[4:] or ['glh-page-home', 'glh-page-news']
front = open(os.path.join(vf, 'modules/builder/assets/front.css')).read()
glhcss = open(os.path.join(glh, 'assets/glh.css')).read()
glhjs = open(os.path.join(glh, 'assets/glh.js')).read()
for n in names:
    html = open(os.path.join(out, n + '.html')).read()
    css = open(os.path.join(out, n + '.css')).read()
    page = ('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            '<style>body{margin:0}</style><style>%s</style><style>%s</style><style>%s</style></head><body>%s<script>%s</script></body></html>') % (front, glhcss, css, html, glhjs)
    open(os.path.join(out, n + '.page.html'), 'w').write(page)
    print('escrito', n + '.page.html')

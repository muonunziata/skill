# Recursos y referencias

Enlaces consultados para elegir las fuentes de datos opcionales del proyecto.

## Mediastack (APILayer) — fuente de noticias opcional

Mediastack agrega noticias de más de 7.500 medios en 50+ países. El Rastreador puede usarla como **fuente adicional**
(`MEDIASTACK_API_KEY` en el `.env`): sus URL pasan por el mismo paso de «abrir y leer la página real» que las demás, así
que nada se publica por confiar a ciegas en la API.

| Recurso | Enlace |
|---|---|
| Precios y planes de Mediastack | https://mediastack.com/pricing |
| Alta / obtener una clave | https://mediastack.com/signup |
| Producto en el catálogo de APILayer | https://apilayer.com/products/mediastack/ |
| Ficha de APILayer en API Evangelist | https://providers.apievangelist.com/providers/api-layer/ |
| Tutorial de Tuts+ sobre Mediastack | https://code.tutsplus.com/mediastack-api--cms-36367a |

Catálogo general de APILayer (el enlace que compartiste): https://apilayer.com/?utm_source=Github&utm_medium=Referral&utm_campaign=Public-apis-repo

### Lo que hay que saber antes de usarla
* **Plan gratuito: 100 consultas al mes.** El proyecto lo respeta: no consulta más de una vez cada `MEDIASTACK_MIN_HOURS`
  (8 h por defecto ≈ 90 al mes) y se detiene al llegar a `MEDIASTACK_MONTHLY_LIMIT`.
* **HTTPS y uso comercial en el plan gratuito:** las páginas oficiales indican que están incluidos, pero varias fuentes de
  terceros dicen lo contrario (HTTP solamente y sin uso comercial). Comprueba los términos vigentes en la página de precios antes
  de usarla en un sitio comercial. Si el plan rechaza HTTPS, el proyecto lo avisa y recurre a HTTP solo con `MEDIASTACK_HTTPS=auto`.
* **Cobertura hiperlocal:** es limitada para un lugar como Lehigh Acres; sirve como complemento, no como sustituto de la
  búsqueda con Google.
* Planes de pago desde ~24,99 USD/mes (10.000 consultas, HTTPS, histórico, uso comercial), según su página de precios.

## Documentación de Claude Code usada para el plugin y los skills

* Plugins: https://code.claude.com/docs/en/plugins
* Crear un marketplace: https://code.claude.com/docs/en/plugins/create-marketplace
* Referencia del manifiesto: https://code.claude.com/docs/en/plugins/manifest-reference
* Skills: https://code.claude.com/docs/en/skills
* Subagentes: https://code.claude.com/docs/en/sub-agents

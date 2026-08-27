# WC Envíos Personalizados

Reemplaza el selector de envío de WooCommerce por tarjetas seleccionables (Pickup / Delivery / Zoom)
y conecta cada método con su catálogo de sedes.

Desarrollado por [Kromaticos](https://kromaticos.com) para BECO.

---

## Qué hace

- **Tarjetas de método de envío** en el checkout, con ícono, descripción y estado seleccionado.
- **Sedes por método**: Pickup lista las tiendas del cliente; Zoom filtra primero por ciudad y
  después por oficina, con buscador incorporado.
- **Bloque "Datos de entrega"** para los métodos que piden dirección, montado sobre los campos
  `shipping_*` nativos de WooCommerce.
- **Costo del envío configurable** por método: gratis o la tarifa de la zona.
- **La sede queda como dirección de envío del pedido**, así aparece en el admin, el email, la
  factura PDF y los plugins de etiquetas sin integraciones extra.
- **Importador CSV** de sedes, pensado para catálogos de transportistas con cientos de oficinas.

## Requisitos

- WordPress con **WooCommerce** activo.
- Checkout **clásico** (shortcode `[woocommerce_checkout]` o plantilla de JetWooBuilder).
  No funciona con el checkout de bloques.
- Opcional: un CPT propio del sitio para las tiendas (por defecto busca `tiendas`).
  Si no existe, el plugin usa su propio CPT «Sedes de envío».

## Instalación

Subir el ZIP desde **Plugins → Añadir nuevo → Subir plugin** y activar.

## Configuración

### 1. Métodos de envío

En **Método de envío** se crea un término por método (`pickup`, `delivery`, `zoom`…). Cada uno define:

| Campo | Para qué sirve |
|---|---|
| Descripción en la tarjeta | Texto gris bajo el nombre |
| Orden en el checkout | Menor número, más a la izquierda |
| Requiere ciudad / sede | Cuántos desplegables se muestran |
| Pide dirección de entrega | Activa el bloque "Datos de entrega" |
| Tarjeta por defecto | Cuál arranca marcada |
| Dónde viven las sedes | Post type del que se leen |
| No cobrar envío | Reemplaza la tarifa de la zona por 0 |
| Caja de resumen | Color, título, dirección e ilustración |

Los íconos e ilustraciones salen de `assets/icons/` según el slug del término
(`pickup.svg`, `pickup-activo.svg`, `ilustracion-pickup.png`), o de una URL propia si se indica.

### 2. Colocación en el checkout

Insertar el shortcode donde deba aparecer el selector:

```
[wcep_envio]
```

Con `[wcep_envio tarjeta="no"]` el plugin no pinta fondo ni sombra, para cuando el contenedor del
constructor de páginas ya los aporta.

Sin shortcode, el selector se inserta automáticamente al final de la columna de facturación.

### 3. Sedes

Cada sede necesita **Sede activa** marcada y, si su método filtra por ciudad, el campo **Ciudad**
lleno y escrito de forma consistente.

Para cargas masivas: **Sedes de envío → Importar sedes**. El CSV admite las columnas
`nombre`, `direccion_completa`, `ciudad` y `telefono`; solo `nombre` es obligatoria.
Reimportar no duplica, y las sedes ausentes del archivo se reportan (se desactivan solo si se pide).

## Diagnóstico

**Sedes de envío → Diagnóstico envíos** muestra el estado de cada requisito, cómo quedó configurado
cada método y cuántas sedes activas tiene. Incluye un probador de la consulta AJAX que usa el checkout.

## Desarrollo

Los estilos se compilan con Tailwind:

```bash
npm install
npm run build    # compila y minifica
npm run watch    # recompila al guardar
```

Las clases se escanean desde `includes/**/*.php` y `assets/js/**/*.js`, así que **hay que recompilar
después de tocar el JS**. El resultado (`assets/css/checkout.css`) se versiona porque el plugin
instalado lo necesita.

`src/`, `node_modules/` y la configuración de build no van en el ZIP de distribución (ver `.distignore`).

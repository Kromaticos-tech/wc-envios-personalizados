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
- **Fecha de retiro calculada** por grupo de horario ("Retira a partir de mañana a las 3:00 pm"),
  con la hora de Venezuela del servidor.
- **Franjas de entrega** para Delivery: según la hora del pedido se ofrecen las opciones vigentes,
  y la elegida se revalida al pagar.
- **Lo prometido queda en el pedido**: la fecha y la frase exacta que vio el cliente se guardan al
  comprar, y se muestran en el admin, en la página del pedido y en los emails.
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
| Prefijo de la línea | Texto fijo antes de la fecha ("Puedes retirar:"). Opcional |
| Nota al pie | Aclaración opcional en gris bajo la fecha. Vacía, no se muestra |
| Franjas de entrega | Tabla de horarios ofrecidos según la hora del pedido |

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
lleno y escrito de forma consistente. Desmarcar **Sede activa** es la forma de sacar una tienda del
checkout: desde el listado se puede hacer en lote y ver de un vistazo cuáles están activas.

El desplegable **Grupo de horario** decide qué fecha de retiro se muestra. Sin grupo, la tienda
aparece en el selector pero sin línea de disponibilidad.

### 4. Grupos de horario

En **Grupos de horario** se define cuándo se puede retirar. La regla se comparte entre todas las
sedes del grupo, así que se edita una vez y no tienda por tienda.

Al activar el plugin se crean **dos grupos de ejemplo ya configurados**, para no tener que cargar
siete campos a mano: *Caracas* (retiro al día siguiente a las 3:00 pm) e *Interior* (retiro los
viernes a las 12:00 pm, con corte los martes). Se renombran, se ajustan o se borran como cualquier
otro término. Solo se crean una vez: si se borran, no vuelven a aparecer.

Lo mismo con **Delivery**: si existe un método con ese slug y todavía no tiene franjas cargadas, se
le rellenan las cuatro estándar. Nunca se pisa algo ya configurado.

| Tipo de regla | Parámetros | Ejemplo |
|---|---|---|
| A partir de N días | Días a sumar, hora | 1 día, 3:00 pm → "Retira a partir de mañana a las 3:00 pm" |
| Próximo día de la semana | Día objetivo, hora, días de compra que alcanzan esa semana | Viernes, 12:00 pm, alcanzan lunes y martes |
| Sin aviso | — | La sede no muestra fecha |

En "Próximo día de la semana" el ancla es el día objetivo **de la semana en curso**: comprando
miércoles, jueves, viernes, sábado o domingo el retiro pasa al de la semana siguiente. El campo
**Texto** arma la frase con `{fecha}` y `{hora}`, y la pantalla de edición muestra cómo se vería hoy.

Para cargas masivas: **Sedes de envío → Importar sedes**. El CSV admite las columnas
`nombre`, `direccion_completa`, `ciudad` y `telefono`; solo `nombre` es obligatoria.
Reimportar no duplica, y las sedes ausentes del archivo se reportan (se desactivan solo si se pide).

### Lo que se guarda en el pedido

| Meta | Contenido |
|---|---|
| `_wcep_metodo_envio` | Slug del método elegido |
| `_wcep_sede_id` | ID de la sede, si el método usa sedes |
| `_wcep_entrega_texto` | La frase que vio el cliente: *"Retira el Viernes 11 de Septiembre, a partir de las 12:00 pm"* o *"Mañana de 3:00 pm a 6:00 pm"* |
| `_wcep_entrega_fecha` | La misma fecha en `AAAA-MM-DD`, para ordenar y filtrar pedidos por día de entrega |

Se guardan **al crear el pedido** y no se recalculan después. Es a propósito: cambiar la regla de un
grupo o la tabla de franjas no debe alterar lo que se le prometió a un pedido ya hecho.

El valor se recalcula en el servidor al guardar, nunca se toma del formulario: el texto es visible
en el navegador y si se aceptara tal cual, cualquiera podría enviar otra fecha.

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

### Empaquetado

```bash
npm run package    # compila el CSS y genera ../wc-envios-personalizados.zip
```

Equivale a `npm run build` seguido de `python bin/empaquetar.py`. El script verifica el ZIP y
falla si algo no está bien.

**No armes el ZIP con `Compress-Archive` de PowerShell, ni con "Enviar a → Carpeta comprimida" de
Windows.** Esas herramientas escriben `external_attr` en 0, o sea que no guardan permisos para
ninguna entrada. En Windows no se nota, pero al descomprimir en un servidor Linux los archivos
quedan sin permiso de lectura, y el fallo que provoca en WordPress no se parece en nada a la causa:

| Síntoma | Por qué |
|---|---|
| "El plugin no existe" al activarlo | PHP no puede leer la cabecera del archivo principal |
| Se crean carpetas como `wc-envios-personalizados-1.12.0` en vez de actualizar la existente | El instalador tampoco puede leer la carpeta extraída, no detecta el directorio raíz del ZIP y usa el nombre del archivo `.zip` como destino |
| Al borrar el plugin, WordPress dice que lo borró pero sigue en la lista | Los archivos sin permisos no se pueden eliminar desde el admin |

Un ZIP válido para WordPress tiene: `external_attr` = `0100666`, ninguna entrada de directorio,
una sola carpeta en la raíz, y **el mismo nombre que esa carpeta** (`wc-envios-personalizados.zip`).
Si el nombre lleva la versión y el instalador cae en el modo de arriba, WordPress crea una carpeta
nueva en lugar de actualizar la que ya existe, y quedan copias duplicadas del plugin conviviendo.

Para comprobar un ZIP ya armado:

```bash
python -c "import zipfile; z=zipfile.ZipFile('wc-envios-personalizados.zip'); print(sorted(set(hex(i.external_attr) for i in z.infolist())))"
# Tiene que imprimir ['0x81b60000'].  Si imprime ['0x0'], el ZIP no sirve.
```

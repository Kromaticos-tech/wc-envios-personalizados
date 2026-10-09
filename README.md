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
- **Costo del envío configurable** por método: gratis, la tarifa de la zona, o la tarifa de la zona
  ajustada por los productos del carrito.
- **Tarifas de delivery por producto**: los SKUs voluminosos pagan un monto fijo y los que no entran
  en un carro no cobran envío, mostrando en su lugar que el flete se acuerda por WhatsApp.
- **Mensaje propio de cada método** en el checkout, para explicar cómo se cobra ese envío (el cobro
  a destino de Zoom y sus montos por kilo, por ejemplo).
- **La sede queda como dirección de envío del pedido**, así aparece en el admin, el email, la
  factura PDF y los plugins de etiquetas sin integraciones extra.
- **Estado "Pedido listo" y aviso de retiro**: al marcar un pedido de Pickup como listo, el cliente
  recibe un correo con la sede donde lo espera su compra.
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

En **Método de envío** hay un término por método (`pickup`, `delivery`, `zoom`…).

En una instalación nueva el plugin **crea los tres ya configurados** al activarse, igual que los
grupos de horario:

| Método | Configuración de arranque |
|---|---|
| **Pick-up** (orden 1) | "Retira en nuestra tienda". Requiere sede (de `tiendas` si existe), no cobra envío, caja de resumen gris con la fecha de retiro, avisa cuando el pedido está listo |
| **Delivery** (orden 2) | "Entregas en Caracas". Pide dirección de entrega, **sin caja de resumen**, muestra solo el monto, aplica tarifas por producto, envío gratis desde $120 (gana sobre las tarifas por producto) y trae las cuatro franjas estándar |
| **Zoom** (orden 3) | "Envíos a todo el país". Requiere ciudad y sede (del CPT propio «Sedes de envío»), cobra un monto fijo de $5 (traslado a la agencia) con las mismas tarifas por producto que Delivery, envío gratis desde $120, sin caja de resumen, mensaje de cobro a destino cargado pero oculto |

Solo se crean si la taxonomía está vacía, y una sola vez: en un sitio que ya tiene métodos cargados
no se agrega ni se pisa nada, y si se borran no vuelven a aparecer. Después se editan como cualquier
otro término. Cada uno define:

| Campo | Para qué sirve |
|---|---|
| Descripción en la tarjeta | Texto gris bajo el nombre |
| Orden en el checkout | Menor número, más a la izquierda |
| Requiere ciudad / sede | Cuántos desplegables se muestran |
| Pide dirección de entrega | Activa el bloque "Datos de entrega" |
| Tarjeta por defecto | Cuál arranca marcada |
| Dónde viven las sedes | Post type del que se leen |
| No cobrar envío | Reemplaza la tarifa de la zona por 0 |
| Mostrar solo el monto | En la línea "Envío" del checkout se oculta el nombre de la tarifa |
| Aplica tarifas por producto | Activa las reglas por SKU de "Tarifas de delivery" |
| Avisar cuando el pedido esté listo | Envía el correo de retiro al marcar el estado "Pedido listo". Marcado en Pickup |
| Caja de resumen | Color, título, dirección e ilustración |
| Mensaje del método | Aviso libre bajo las tarjetas, con saltos de línea. Funciona con y sin sede |
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
siete campos a mano: *Caracas* (retiro al día siguiente a las 3:00 pm) e *Interior* (retiro a partir
del viernes a las 12:00 pm, con corte los martes). Se renombran, se ajustan o se borran como cualquier
otro término. Solo se crean una vez: si se borran, no vuelven a aparecer.

Lo mismo con **Delivery**: si existe un método con ese slug y todavía no tiene franjas cargadas, se
le rellenan las cuatro estándar. Nunca se pisa algo ya configurado. (Los métodos en sí también vienen
creados: ver "1. Métodos de envío").

| Tipo de regla | Parámetros | Ejemplo |
|---|---|---|
| A partir de N días | Días a sumar, hora | 1 día, 3:00 pm → "Retira a partir de mañana a las 3:00 pm" |
| Próximo día de la semana | Día objetivo, hora, días de compra que alcanzan esa semana | Viernes, 12:00 pm, alcanzan lunes y martes → "Retira a partir del Viernes 11 de Septiembre, desde las 12:00 pm" |
| Sin aviso | — | La sede no muestra fecha |

En "Próximo día de la semana" el ancla es el día objetivo **de la semana en curso**: comprando
miércoles, jueves, viernes, sábado o domingo el retiro pasa al de la semana siguiente. El campo
**Texto** arma la frase con `{fecha}` y `{hora}`, y la pantalla de edición muestra cómo se vería hoy.

Para cargas masivas: **Sedes de envío → Importar sedes**. El CSV admite las columnas
`nombre`, `direccion_completa`, `ciudad` y `telefono`; solo `nombre` es obligatoria.
Reimportar no duplica, y las sedes ausentes del archivo se reportan (se desactivan solo si se pide).

### 5. Tarifas de delivery por producto

El precio normal del delivery se sigue configurando en **WooCommerce → Ajustes → Envío**, en la zona
correspondiente. En **Sedes de envío → Tarifas de delivery** se cargan solo las excepciones, por SKU:

| Lista | Qué hace |
|---|---|
| Productos con tarifa especial | Cobran el monto indicado en lugar del de la zona (cavas, maletas, toldos, sillas y mesas plegables, mesas de planchar, tendederos, cestas, ventiladores) |
| Productos que se acuerdan por WhatsApp | No cobran envío; la línea "Envío" muestra el mensaje en lugar del monto (muebles, máquinas de hacer ejercicio) |

Un SKU por línea; también valen separados por coma. Las reglas se aplican en los métodos que tengan
marcado **Aplica tarifas por producto** (Delivery), y con las dos listas vacías el checkout se
comporta como si no existieran.

Cómo se resuelve un carrito:

- Basta **un** producto de la lista de WhatsApp para que el pedido completo salga con envío en 0 y el
  mensaje. La compra se completa igual: el flete se cuadra después, como en Pickup.
- Si no hay ninguno pero sí hay un producto de la lista especial, se cobra **el mayor** entre la
  tarifa de la zona y el monto especial.
- La tarifa es **por pedido, no por unidad**: tres cavas pagan lo mismo que una.
- Las variaciones se comparan por su propio SKU y, si no tienen, por el del producto padre.

Al guardar la pantalla se invalida el caché de tarifas de WooCommerce. Sin eso, los carritos ya
armados seguirían mostrando el precio viejo hasta que el cliente tocara algo.

### 6. Aviso de "pedido listo"

El desplegable **Estado** de la ficha del pedido trae un valor propio, **Pedido listo**, entre
"Procesando" y "Completado". Es el rato en que la compra ya está empacada y espera en el mostrador:
antes había que marcar "Completado" para avisar, y el pedido quedaba cerrado en el sistema mientras
la caja todavía lo tenía en el estante.

Al marcarlo, el cliente recibe un correo con el nombre, la dirección, el horario y el teléfono de la
sede que eligió. Se puede marcar de a uno desde la ficha o en lote desde la lista de pedidos
(**Acciones en lote → Marcar como pedido listo**), y reenviarse desde **Acciones del pedido** si el
cliente dice que no le llegó.

El correo sale **solo en los métodos con la casilla "Avisar cuando el pedido esté listo"**, que viene
marcada en Pickup. Delivery y Zoom pueden usar igual el estado para saber que la preparación terminó,
pero no envían nada: en uno el pedido sale hacia la casa del cliente y en el otro lo mueve el
transportista. Un pedido sin sede tampoco dispara el correo, porque no habría a dónde mandar a nadie.

El asunto, el encabezado y el texto final se editan en **WooCommerce → Ajustes → Correos
electrónicos → "Pedido listo para retirar"**. Para cambiar el diseño, copiar
`templates/emails/pedido-listo.php` a `tu-tema/woocommerce/emails/pedido-listo.php`.

Los datos de la sede se leen en el momento de enviar, no de una copia guardada en el pedido: entre la
compra y el aviso pueden pasar días, y lo que el cliente necesita es el teléfono y la dirección de
hoy.

### Lo que se guarda en el pedido

| Meta | Contenido |
|---|---|
| `_wcep_metodo_envio` | Slug del método elegido |
| `_wcep_sede_id` | ID de la sede, si el método usa sedes |
| `_wcep_entrega_texto` | La frase que vio el cliente: *"Retira a partir del Viernes 11 de Septiembre, desde las 12:00 pm"* o *"Mañana de 3:00 pm a 6:00 pm"* |
| `_wcep_entrega_fecha` | La misma fecha en `AAAA-MM-DD`, para ordenar y filtrar pedidos por día de entrega |
| `_wcep_delivery_tarifa` | Con qué regla se cobró el envío: `estandar`, `especial`, `whatsapp` o `gratis_monto` (llegó al mínimo de compra y no pagó envío). Los `whatsapp` salen con envío en 0 y se avisan en el admin y en el correo del administrador |

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

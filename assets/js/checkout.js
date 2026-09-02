jQuery(function ($) {
    if (typeof WCEP === 'undefined' || !WCEP.metodos.length) {
        return;
    }

    const $formularioCheckout = $('form.checkout');

    // El selector vive dentro del formulario clásico (shortcode o plantilla de JetWooBuilder).
    // Con el checkout de bloques ese formulario no existe y antes el plugin fallaba en silencio.
    if (!$formularioCheckout.length) {
        console.warn('[WCEP] No se encontró "form.checkout". El selector de envío requiere el checkout clásico, no el de bloques de WooCommerce.');
        return;
    }

    const CLASES_TARJETA        = 'wcep-card flex-1 border rounded-xl p-4 text-center cursor-pointer transition border-gray-200 hover:border-blue-400';
    const CLASES_TARJETA_ACTIVA = 'border-blue-600 ring-1 ring-blue-600';

    // Los dos estilos de caja del diseño: Pickup va gris, Delivery morado.
    // Los colores viven en el CSS; aquí solo se elige el modificador.
    const ESTILOS_AVISO = ['morado', 'gris'];

    const textos = WCEP.textos || {};

    // Todo lo que venga de la base de datos (nombres de sede, direcciones, avisos, URLs de imágenes)
    // se concatena dentro de HTML, así que se escapa. Cubre comillas para que sirva también en atributos.
    function escaparHtml(valor) {
        return String(valor === null || valor === undefined ? '' : valor)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function clasesCajaAviso(metodo) {
        const estilo = ESTILOS_AVISO.indexOf(metodo.estilo_aviso) !== -1 ? metodo.estilo_aviso : 'morado';
        return 'wcep-caja-aviso--' + estilo;
    }

    function titulo(texto) {
        return '<h3 class="wcep-titulo">' + escaparHtml(texto) + '</h3>';
    }

    // --- Inserción en la página --------------------------------------------------------------

    const $selectorEnvio = $(
        '<div class="wcep-tarjeta">' +
            titulo(textos.tituloSelector || 'Selecciona el tipo de envío') +
            '<div class="wcep-cards flex gap-3"></div>' +
            '<div class="wcep-detalle"></div>' +
        '</div>'
    );

    // Dónde se pinta el selector, en orden de preferencia:
    //
    // 1. El shortcode [wcep_envio], que el usuario coloca en la plantilla donde quiera. Es la vía
    //    recomendada: con Elementor/JetWooBuilder la estructura de filas y columnas cambia de una
    //    plantilla a otra, y adivinarla desde JS rompe el layout (insertar al lado de la columna de
    //    facturación la convertiría en una tercera columna, no en una tarjeta debajo).
    // 2. Al final de la columna de facturación, como sección extra dentro de esa misma tarjeta.
    // 3. Al principio del formulario. Se ve fuera de lugar, pero al menos es usable.
    //
    // En los casos 2 y 3 el selector se pinta con su propia tarjeta; con el shortcode no, porque
    // el contenedor de Elementor ya aporta fondo, borde y sombra.
    function insertarSelector() {
        const $puntoMontaje = $('.wcep-punto-montaje').first();

        if ($puntoMontaje.length) {
            if (!$puntoMontaje.closest('form.checkout').length) {
                console.warn('[WCEP] El shortcode [wcep_envio] está fuera de form.checkout. Muévelo dentro de la plantilla del checkout o los datos no se enviarán con el pedido.');
            }
            if (!$puntoMontaje.hasClass('wcep-sin-tarjeta')) {
                $selectorEnvio.addClass('wcep-tarjeta');
            }
            $puntoMontaje.append($selectorEnvio);
            return;
        }

        // Sin shortcode el selector cae dentro de la tarjeta de facturación, así que se pinta como
        // sección y no como tarjeta: una tarjeta dentro de otra se ve mal.
        $selectorEnvio.addClass('wcep-seccion');

        const $columnaFacturacion = $('.elementor-widget-jet-checkout-billing, .woocommerce-billing-fields')
            .first()
            .closest('.e-con, .elementor-container, .elementor-widget-wrap');

        if ($columnaFacturacion.length && $columnaFacturacion.closest('form.checkout').length) {
            $columnaFacturacion.append($selectorEnvio);
            return;
        }

        console.warn('[WCEP] No se encontró dónde colocar el selector. Se insertó al inicio del formulario; usa el shortcode [wcep_envio] para ubicarlo bien.');
        $formularioCheckout.prepend($selectorEnvio);
    }

    insertarSelector();

    const $contenedorTarjetas = $selectorEnvio.find('.wcep-cards');
    const $panelDetalle       = $selectorEnvio.find('.wcep-detalle');

    // Sedes devueltas por el último AJAX; se consultan al elegir una en el select.
    let sedesCargadas = [];

    WCEP.metodos.forEach(function (metodo) {
        const descripcion = metodo.descripcion
            ? '<span class="wcep-card-descripcion">' + escaparHtml(metodo.descripcion) + '</span>'
            : '';

        $contenedorTarjetas.append(
            '<div class="' + CLASES_TARJETA + '" data-slug="' + escaparHtml(metodo.slug) + '">' +
                '<img src="' + escaparHtml(metodo.icono) + '" alt="" class="wcep-card-icono">' +
                '<strong class="wcep-card-nombre">' + escaparHtml(metodo.nombre) + '</strong>' +
                descripcion +
            '</div>'
        );
    });

    // Cada método trae dos imágenes (gris y azul). Se intercambia el src en vez de recolorear por CSS,
    // que con <img> no es posible.
    function pintarIconos(slugActivo) {
        $contenedorTarjetas.find('.wcep-card').each(function () {
            const $tarjeta = $(this);
            const metodo   = buscarMetodoPorSlug($tarjeta.data('slug'));
            if (!metodo) return;

            const esActiva = metodo.slug === slugActivo;
            const url      = (esActiva && metodo.iconoActivo) ? metodo.iconoActivo : metodo.icono;

            $tarjeta.find('.wcep-card-icono').attr('src', url);
        });
    }

    $contenedorTarjetas.on('click', '.wcep-card', function () {
        const $tarjetaElegida = $(this);

        $contenedorTarjetas.find('.wcep-card').removeClass(CLASES_TARJETA_ACTIVA).removeAttr('data-active');
        $tarjetaElegida.addClass(CLASES_TARJETA_ACTIVA).attr('data-active', '1');

        const slugElegido   = $tarjetaElegida.data('slug');
        const metodoElegido = buscarMetodoPorSlug(slugElegido);
        if (!metodoElegido) return;

        pintarIconos(slugElegido);
        guardarCampoOculto('wcep_metodo_envio', slugElegido);
        renderDetalle(metodoElegido);
        aplicarDatosEntrega(metodoElegido);
        $(document.body).trigger('update_checkout'); // fuerza a WooCommerce a recalcular el costo de envío ya
    });

    function buscarMetodoPorSlug(slug) {
        return WCEP.metodos.find(function (metodo) {
            return metodo.slug === slug;
        });
    }

    function renderDetalle(metodo) {
        $panelDetalle.empty();
        guardarCampoOculto('wcep_sede_id', '');
        // Se limpia al cambiar de tarjeta: si no, la franja elegida en Delivery seguiría viajando
        // en el POST después de pasarse a Pickup.
        guardarCampoOculto('wcep_franja', '');

        if (!metodo.requiere_sede) {
            // Delivery: no elige sede, así que los datos de la caja salen del propio método.
            if (metodo.mostrar_resumen && metodo.aviso_titulo) {
                $panelDetalle.append(cajaResumen({
                    metodo:    metodo,
                    titulo:    metodo.aviso_titulo,
                    direccion: metodo.aviso_direccion
                }));
            }
            agregarFranjas(metodo);
            return;
        }

        $panelDetalle.append(titulo(textos.tituloTienda || 'Selecciona tienda'));

        if (metodo.requiere_ciudad) {
            $panelDetalle.append('<select class="wcep-ciudad wcep-select"></select>');
        }
        $panelDetalle.append('<select class="wcep-sede wcep-select"></select>');
        $panelDetalle.append('<div class="wcep-aviso"></div>');
        agregarFranjas(metodo);

        if (metodo.requiere_ciudad) {
            // Las sedes se cargan recién cuando hay una ciudad elegida, para no listar todo el país.
            reiniciarSelect($panelDetalle.find('.wcep-sede'), placeholderSede(metodo), true);
            cargarCiudades(metodo.slug);
            return;
        }

        cargarSedes(metodo.slug, '');
    }

    // Las franjas no dependen de la sede, así que el bloque se agrega al pintar el detalle y no
    // al elegir tienda. Un método sin franjas configuradas simplemente no lo tiene.
    function agregarFranjas(metodo) {
        if (!metodo.tiene_franjas) return;

        $panelDetalle.append('<div class="wcep-franjas"></div>');
        cargarFranjas(metodo);
    }

    function placeholderSede(metodo) {
        return metodo.placeholder_sede || textos.seleccionaSede || 'Selecciona sede';
    }

    function metodoActivo() {
        return buscarMetodoPorSlug($contenedorTarjetas.find('.wcep-card[data-active="1"]').data('slug'));
    }

    // --- Combobox --------------------------------------------------------------------------------
    // Con 118 ciudades un <select> plano es inmanejable, pero Select2 apila dos cajas: la del
    // selector y un buscador aparte debajo, y parecen dos campos distintos. Aquí el mismo campo
    // donde se lee la selección es donde se escribe para filtrar.
    //
    // El <select> original se conserva oculto como fuente de verdad: el resto del código sigue
    // leyendo su value y escuchando su evento "change" sin enterarse de nada.

    // Compara sin distinguir mayúsculas ni acentos: "guarico" encuentra "Guárico".
    function normalizarTexto(texto) {
        return String(texto)
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '');
    }

    function destruirCombo($select) {
        const $combo = $select.data('wcepCombo');
        if ($combo) {
            $combo.remove();
            $select.removeData('wcepCombo');
        }
        $select.removeClass('wcep-select-oculto');
    }

    function crearCombo($select) {
        destruirCombo($select);

        const opciones = [];
        $select.find('option').each(function () {
            if (this.value !== '') {
                opciones.push({ valor: this.value, etiqueta: $(this).text() });
            }
        });

        if (!opciones.length) return;

        const placeholder = $select.find('option[value=""]').first().text();

        const $combo = $(
            '<div class="wcep-combo">' +
                '<input type="text" class="wcep-combo-campo" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false">' +
                '<span class="wcep-combo-flecha" aria-hidden="true"></span>' +
                '<ul class="wcep-combo-lista" role="listbox" hidden></ul>' +
            '</div>'
        );

        const $campo = $combo.find('.wcep-combo-campo').attr('placeholder', placeholder);
        const $lista = $combo.find('.wcep-combo-lista');

        let resaltada = -1;

        function pintarLista(filtro) {
            const buscado = normalizarTexto(filtro || '');
            const visibles = opciones.filter(function (opcion) {
                return !buscado || normalizarTexto(opcion.etiqueta).indexOf(buscado) !== -1;
            });

            $lista.empty();

            if (!visibles.length) {
                $lista.append('<li class="wcep-combo-vacio">' + escaparHtml(textos.sinResultados || 'Sin resultados') + '</li>');
            } else {
                visibles.forEach(function (opcion) {
                    $lista.append(
                        '<li class="wcep-combo-opcion" role="option" data-valor="' + escaparHtml(opcion.valor) + '">' +
                            escaparHtml(opcion.etiqueta) +
                        '</li>'
                    );
                });
            }

            resaltada = -1;
        }

        function abrir() {
            if ($campo.prop('disabled')) return;
            pintarLista('');
            $lista.prop('hidden', false);
            $campo.attr('aria-expanded', 'true');
        }

        function cerrar() {
            $lista.prop('hidden', true);
            $campo.attr('aria-expanded', 'false');
            // Si se escribió algo sin elegir, se recupera la etiqueta de lo seleccionado:
            // el campo nunca debe quedar con texto que no corresponda al valor real.
            $campo.val(etiquetaSeleccionada());
        }

        function etiquetaSeleccionada() {
            const valor = $select.val();
            const elegida = opciones.find(function (opcion) { return opcion.valor === valor; });
            return elegida ? elegida.etiqueta : '';
        }

        function elegir(valor) {
            $select.val(valor).trigger('change');
            $campo.val(etiquetaSeleccionada());
            $lista.prop('hidden', true);
            $campo.attr('aria-expanded', 'false');
        }

        function moverResaltado(paso) {
            const $opciones = $lista.find('.wcep-combo-opcion');
            if (!$opciones.length) return;

            resaltada = (resaltada + paso + $opciones.length) % $opciones.length;
            $opciones.removeClass('wcep-combo-opcion--activa');

            const $activa = $opciones.eq(resaltada).addClass('wcep-combo-opcion--activa');
            $activa[0].scrollIntoView({ block: 'nearest' });
        }

        $campo.on('focus click', abrir);

        $campo.on('input', function () {
            pintarLista($(this).val());
            $lista.prop('hidden', false);
            $campo.attr('aria-expanded', 'true');
        });

        $campo.on('keydown', function (evento) {
            if (evento.key === 'ArrowDown') { evento.preventDefault(); moverResaltado(1); return; }
            if (evento.key === 'ArrowUp')   { evento.preventDefault(); moverResaltado(-1); return; }
            if (evento.key === 'Escape')    { cerrar(); return; }

            if (evento.key === 'Enter') {
                const $activa = $lista.find('.wcep-combo-opcion--activa');
                if ($activa.length) {
                    evento.preventDefault();
                    elegir($activa.data('valor'));
                }
            }
        });

        $lista.on('mousedown', '.wcep-combo-opcion', function (evento) {
            // mousedown y no click: el blur del campo cerraría la lista antes de que llegue el click.
            evento.preventDefault();
            elegir($(this).data('valor'));
        });

        $campo.on('blur', function () {
            // Diferido para no adelantarse al mousedown de la lista.
            window.setTimeout(cerrar, 120);
        });

        $select.addClass('wcep-select-oculto').after($combo);
        $select.data('wcepCombo', $combo);
        $campo.val(etiquetaSeleccionada());
    }

    // Deja un select con solo su opción placeholder, opcionalmente deshabilitado.
    function reiniciarSelect($select, textoPlaceholder, deshabilitado) {
        destruirCombo($select);

        $select
            .empty()
            .append('<option value="">' + escaparHtml(textoPlaceholder) + '</option>')
            .prop('disabled', !!deshabilitado);
    }

    $(document).on('change', '.wcep-ciudad', function () {
        const metodo       = metodoActivo();
        const ciudadFiltro = $(this).val();
        if (!metodo) return;

        $panelDetalle.find('.wcep-aviso').empty();

        if (!ciudadFiltro) {
            reiniciarSelect($panelDetalle.find('.wcep-sede'), placeholderSede(metodo), true);
            guardarCampoOculto('wcep_sede_id', '');
            return;
        }

        cargarSedes(metodo.slug, ciudadFiltro);
    });

    $(document).on('change', '.wcep-sede', function () {
        const sedeId = $(this).val();
        const metodo = metodoActivo();
        guardarCampoOculto('wcep_sede_id', sedeId);

        const sedeElegida = sedesCargadas.find(function (sede) {
            return String(sede.id) === String(sedeId);
        });

        const $contenedorAviso = $panelDetalle.find('.wcep-aviso').empty();
        if (!sedeElegida || !metodo) return;

        if (metodo.mostrar_resumen) {
            $contenedorAviso.append(cajaResumen({
                metodo:         metodo,
                titulo:         sedeElegida.nombre,
                direccion:      sedeElegida.direccion,
                disponibilidad: sedeElegida.disponibilidad
            }));
        }
        if (sedeElegida.costo_envio_mostrar && sedeElegida.costo_envio_texto) {
            $contenedorAviso.append(cajaCosto(sedeElegida.costo_envio_texto));
        }
    });

    // Resumen de lo elegido: avatar, nombre, dirección, ilustración y —cuando la sede tiene grupo
    // de horario— la línea de disponibilidad ("Puedes retirar: Retira a partir de mañana a las
    // 3:00 pm"). El texto llega ya calculado desde PHP: acá no se hacen cuentas con fechas, porque
    // el reloj y la zona horaria del navegador son los del cliente y no los de la tienda.
    function cajaResumen(datos) {
        const ilustracion = datos.metodo.ilustracion;

        return '<div class="wcep-caja-aviso ' + clasesCajaAviso(datos.metodo) + '">' +
            '<div class="flex items-center justify-between gap-3">' +
                '<div class="flex items-center gap-3">' +
                    (WCEP.iconoSedeAvatar ? '<img src="' + escaparHtml(WCEP.iconoSedeAvatar) + '" class="wcep-aviso-avatar" alt="">' : '') +
                    '<div>' +
                        '<p class="wcep-aviso-nombre">' + escaparHtml(datos.titulo) + '</p>' +
                        (datos.direccion ? '<p class="wcep-aviso-direccion">' + escaparHtml(datos.direccion) + '</p>' : '') +
                    '</div>' +
                '</div>' +
                (ilustracion ? '<img src="' + escaparHtml(ilustracion) + '" class="wcep-aviso-ilustracion" alt="">' : '') +
            '</div>' +
            lineaDisponibilidad(datos.metodo, datos.disponibilidad) +
        '</div>';
    }

    // El prefijo es opcional y se concatena al texto del grupo. Se pueden repartir de dos formas:
    // todo en el texto del grupo ("Retira a partir de mañana a las 3:00 pm") o partido, con el
    // prefijo fijo en negro ("Puedes retirar:") y el grupo aportando solo la fecha.
    function lineaDisponibilidad(metodo, texto) {
        if (!texto) return '';

        const prefijo = metodo.prefijo_disponibilidad
            ? '<span class="wcep-aviso-prefijo">' + escaparHtml(metodo.prefijo_disponibilidad) + '</span> '
            : '';

        return '<div class="wcep-aviso-disponibilidad">' +
            '<p class="wcep-aviso-texto">' + prefijo + '<span class="wcep-aviso-fecha">' + escaparHtml(texto) + '</span></p>' +
            notaDisponibilidad(metodo) +
        '</div>';
    }

    // Aclaración opcional bajo la fecha. Sin texto cargado en el método, no se pinta nada.
    function notaDisponibilidad(metodo) {
        return metodo.nota_disponibilidad
            ? '<p class="wcep-aviso-nota">' + escaparHtml(metodo.nota_disponibilidad) + '</p>'
            : '';
    }

    // --- Franjas de entrega ----------------------------------------------------------------------
    // Qué franjas aplican depende de la hora, así que se piden al servidor en cada clic y no viajan
    // con la página: el checkout puede estar cacheado, o el cliente puede haber abierto la pestaña
    // antes del mediodía y elegir después del corte.

    function cargarFranjas(metodo) {
        const $bloque = $panelDetalle.find('.wcep-franjas');
        if (!$bloque.length) return;

        $.post(WCEP.ajaxUrl, {
            action: 'wcep_get_franjas',
            nonce: WCEP.nonce,
            metodo: metodo.slug
        }, function (respuesta) {
            const franjas = respuesta.success ? respuesta.data : [];

            if (!franjas.length) {
                $bloque.html('<p class="wcep-aviso-nota">' + escaparHtml(textos.sinFranjas || 'No hay horarios de entrega disponibles en este momento.') + '</p>');
                return;
            }

            let html = titulo(metodo.titulo_franjas || textos.tituloFranjas || '¿Cuándo quieres recibirlo?');

            franjas.forEach(function (franja) {
                html += '<label class="wcep-franja">' +
                    '<input type="radio" name="wcep_franja_opcion" value="' + escaparHtml(franja.id) + '">' +
                    '<span class="wcep-franja-etiqueta">' + escaparHtml(franja.etiqueta) + '</span>' +
                '</label>';
            });

            // Ninguna viene marcada: elegir el horario es una decisión del cliente, y premarcar la
            // primera hace que muchos paguen sin mirarla y después reclamen la entrega a otra hora.
            $bloque.html(html + notaDisponibilidad(metodo));
        });
    }

    $(document).on('change', 'input[name="wcep_franja_opcion"]', function () {
        guardarCampoOculto('wcep_franja', $(this).val());
    });

    function cajaCosto(textoCosto) {
        return '<div class="wcep-caja-costo">' +
            '<p class="wcep-aviso-texto">' +
                '<span class="wcep-aviso-prefijo">' + escaparHtml(textos.costoAprox || 'Costo aproximado de envío:') + '</span> ' +
                escaparHtml(textoCosto) +
            '</p>' +
        '</div>';
    }

    // Puebla el select de ciudad. Antes nadie lo llenaba: quedaba vacío y las sedes salían sin filtrar.
    function cargarCiudades(metodoSlug) {
        const $selectCiudad = $panelDetalle.find('.wcep-ciudad');
        const placeholder   = textos.seleccionaCiudad || 'Selecciona tu ciudad';

        reiniciarSelect($selectCiudad, placeholder, true);

        $.post(WCEP.ajaxUrl, {
            action: 'wcep_get_ciudades',
            nonce: WCEP.nonce,
            metodo: metodoSlug
        }, function (respuesta) {
            const ciudades = respuesta.success ? respuesta.data : [];

            reiniciarSelect($selectCiudad, placeholder, false);

            ciudades.forEach(function (ciudad) {
                $selectCiudad.append('<option value="' + escaparHtml(ciudad) + '">' + escaparHtml(ciudad) + '</option>');
            });

            crearCombo($selectCiudad);
        });
    }

    function cargarSedes(metodoSlug, ciudadFiltro) {
        $.post(WCEP.ajaxUrl, {
            action: 'wcep_get_sedes',
            nonce: WCEP.nonce,
            metodo: metodoSlug,
            ciudad: ciudadFiltro
        }, function (respuesta) {
            sedesCargadas = respuesta.success ? respuesta.data : [];

            const metodo        = buscarMetodoPorSlug(metodoSlug);
            const $selectSede   = $panelDetalle.find('.wcep-sede');
            const hayResultados = sedesCargadas.length > 0;

            reiniciarSelect(
                $selectSede,
                hayResultados ? placeholderSede(metodo || {}) : (textos.sinSedes || 'No hay sedes disponibles'),
                !hayResultados
            );
            $panelDetalle.find('.wcep-aviso').empty();

            sedesCargadas.forEach(function (sede) {
                $selectSede.append('<option value="' + escaparHtml(sede.id) + '">' + escaparHtml(sede.nombre) + '</option>');
            });

            if (hayResultados) {
                crearCombo($selectSede);
            }
        });
    }

    // --- Bloque "Datos de entrega" -------------------------------------------------------------
    // Son los campos shipping_* nativos de WooCommerce, marcados desde PHP con una clase.
    // Se trasladan aquí para que queden donde los pone el diseño; siguen dentro de form.checkout,
    // así que se envían igual.

    const $bloqueEntrega = $(
        '<div class="wcep-datos-entrega">' +
            titulo(textos.tituloEntrega || 'Datos de entrega') +
            '<div class="wcep-campos-entrega"></div>' +
        '</div>'
    );
    $selectorEnvio.append($bloqueEntrega);

    // Si la plantilla no pinta el bloque de envío, el plugin los renderiza en #wcep-campos-origen
    // (un contenedor oculto al final del body) y se trasladan aquí. Al quedar dentro de la tarjeta,
    // que a su vez está dentro de form.checkout, se envían con el pedido normalmente.
    // El contenedor de origen se mete dentro del formulario antes de repartir: dentro quedan los
    // campos que WooCommerce necesita pero no se piden (país, estado, ciudad, código postal),
    // que así siguen viajando en el POST aunque nunca se vean.
    const $origenCampos = $('#wcep-campos-origen');
    if ($origenCampos.length && !$origenCampos.closest('form.checkout').length) {
        $origenCampos.appendTo($formularioCheckout);
    }

    const $camposEntrega = $('.' + WCEP.claseCampoEntrega);

    if ($camposEntrega.length) {
        $bloqueEntrega.find('.wcep-campos-entrega').append($camposEntrega);
    } else {
        console.warn('[WCEP] No se encontraron los campos de envío (shipping_*). El bloque "Datos de entrega" queda oculto.');
    }

    function aplicarDatosEntrega(metodo) {
        // Sin campos que mostrar, el bloque se oculta entero: si no, quedaría el título solo.
        $bloqueEntrega.toggle(!!metodo.muestra_datos_entrega && $camposEntrega.length > 0);
    }

    // "Nombre del que recibe" arranca con el nombre de facturación y el cliente lo edita si el
    // paquete va para otra persona. Se deja de sincronizar en cuanto lo toca a mano.
    function sincronizarNombreReceptor() {
        const $nombreReceptor = $('#shipping_first_name');
        if (!$nombreReceptor.length) return;

        const $nombreFacturacion   = $('#billing_first_name');
        const $apellidoFacturacion = $('#billing_last_name');

        function nombreFacturacionCompleto() {
            return [$nombreFacturacion.val(), $apellidoFacturacion.val()]
                .join(' ')
                .replace(/\s+/g, ' ')
                .trim();
        }

        // WooCommerce ya prellena shipping_first_name con el nombre guardado del cliente, que suele
        // ser solo el nombre de pila. Ese valor no cuenta como edición manual: si coincide con algo
        // que salió de facturación, se completa con el apellido.
        const valorInicial   = $nombreReceptor.val().trim();
        const nombreDePila   = ($nombreFacturacion.val() || '').trim();
        let   editadoAMano   = valorInicial !== ''
            && valorInicial !== nombreDePila
            && valorInicial !== nombreFacturacionCompleto();

        function copiarDesdeFacturacion() {
            if (editadoAMano) return;
            $nombreReceptor.val(nombreFacturacionCompleto());
        }

        $nombreReceptor.on('input', function () {
            editadoAMano = $(this).val().trim() !== '';
        });

        $nombreFacturacion.add($apellidoFacturacion).on('input change', copiarDesdeFacturacion);
        copiarDesdeFacturacion();
    }

    sincronizarNombreReceptor();

    // País, estado, ciudad y código postal de envío están ocultos: nunca se le piden al cliente.
    // Pero WooCommerce los usa para decidir la ZONA de envío, y si llegan vacíos el pedido cae en
    // "Resto del mundo" —sin métodos— y el checkout se bloquea con "No hay métodos de envío".
    // Por eso se copian desde facturación en cuanto el cliente la completa.
    function sincronizarUbicacionEnvio() {
        const EQUIVALENCIAS = [
            { origen: '#billing_country',  destino: '#shipping_country' },
            { origen: '#billing_state',    destino: '#shipping_state' },
            { origen: '#billing_postcode', destino: '#shipping_postcode' },
            // billing_city2 cubre las plantillas que desactivaron el campo estándar.
            { origen: '#billing_city, #billing_city2', destino: '#shipping_city' }
        ];

        function copiar() {
            EQUIVALENCIAS.forEach(function (par) {
                const $destino = $(par.destino);
                if (!$destino.length) return;

                const $origen = $(par.origen).filter(function () {
                    return $(this).val() !== '' && $(this).val() !== null;
                }).first();

                if ($origen.length) {
                    $destino.val($origen.val());
                }
            });
        }

        const selectoresOrigen = EQUIVALENCIAS.map(function (par) { return par.origen; }).join(', ');
        $(document).on('change input', selectoresOrigen, copiar);
        copiar();
    }

    sincronizarUbicacionEnvio();

    // Los campos ocultos viven dentro de form.checkout para que viajen con el pedido al enviarlo.
    function guardarCampoOculto(nombreCampo, valor) {
        $('#' + nombreCampo).remove();
        $('<input>', { type: 'hidden', id: nombreCampo, name: nombreCampo, value: valor }).appendTo($formularioCheckout);
    }

    // Arranca con una tarjeta marcada. Sin esto no había método en sesión hasta el primer click,
    // y WooCommerce mostraba sus tarifas normales mientras tanto.
    function marcarTarjetaInicial() {
        const $tarjetaPorDefecto = $contenedorTarjetas.find('.wcep-card[data-slug="' + WCEP.slugPredeterminado + '"]');
        const $tarjetaAMarcar    = $tarjetaPorDefecto.length ? $tarjetaPorDefecto : $contenedorTarjetas.find('.wcep-card').first();

        $tarjetaAMarcar.trigger('click');
    }

    marcarTarjetaInicial();
});

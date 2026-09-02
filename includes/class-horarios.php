<?php
if (!defined('ABSPATH')) exit;

// Calcula las fechas que se le muestran al cliente en el checkout: cuándo puede retirar (Pickup)
// y en qué franjas puede recibir (Delivery).
//
// Todo se resuelve acá, en PHP, y nunca en el navegador: el reloj y la zona horaria del
// dispositivo del cliente son suyos, no de la tienda. Un cliente en España vería otra fecha.
//
// La clase no sabe nada de tiendas ni de métodos de envío: recibe una regla ya leída y devuelve
// texto. Eso la hace probable a mano y reutilizable para cualquier grupo que se cree después.
class WCEP_Horarios {

    // Zona fija en vez de wp_timezone(): el ajuste del sitio lo puede cambiar cualquiera desde
    // Ajustes → Generales, y si se mueve media hora el corte de las 12:00 pm de Delivery empieza
    // a dar la franja equivocada en el borde. Esto no debe depender de una preferencia editable.
    const ZONA = 'America/Caracas';

    // Tipos de regla disponibles. Entre los dos cubren los casos del cliente y cualquier
    // combinación parecida que aparezca después, sin tocar código.
    const TIPO_NINGUNO    = '';
    const TIPO_DIAS       = 'dias';
    const TIPO_DIA_SEMANA = 'dia_semana';

    public static function zona() {
        return new DateTimeZone(apply_filters('wcep_zona_horaria', self::ZONA));
    }

    public static function ahora() {
        return new DateTimeImmutable('now', self::zona());
    }

    // Lunes = 1 … Domingo = 7, igual que el formato "N" de PHP. Se usa como clave en toda la clase.
    public static function dias_semana() {
        return [
            1 => __('Lunes', 'wc-envios-personalizados'),
            2 => __('Martes', 'wc-envios-personalizados'),
            3 => __('Miércoles', 'wc-envios-personalizados'),
            4 => __('Jueves', 'wc-envios-personalizados'),
            5 => __('Viernes', 'wc-envios-personalizados'),
            6 => __('Sábado', 'wc-envios-personalizados'),
            7 => __('Domingo', 'wc-envios-personalizados'),
        ];
    }

    // Nombres propios en vez de wp_date(): esa función traduce según el idioma del sitio, así que
    // en un WordPress en inglés saldría "Friday 4 de September". Acá el resultado no depende de
    // cómo esté configurado el idioma.
    private static function meses() {
        return [
            1  => __('Enero', 'wc-envios-personalizados'),
            2  => __('Febrero', 'wc-envios-personalizados'),
            3  => __('Marzo', 'wc-envios-personalizados'),
            4  => __('Abril', 'wc-envios-personalizados'),
            5  => __('Mayo', 'wc-envios-personalizados'),
            6  => __('Junio', 'wc-envios-personalizados'),
            7  => __('Julio', 'wc-envios-personalizados'),
            8  => __('Agosto', 'wc-envios-personalizados'),
            9  => __('Septiembre', 'wc-envios-personalizados'),
            10 => __('Octubre', 'wc-envios-personalizados'),
            11 => __('Noviembre', 'wc-envios-personalizados'),
            12 => __('Diciembre', 'wc-envios-personalizados'),
        ];
    }

    // --- Reglas de disponibilidad (Pickup) -----------------------------------------------------

    // Fecha en que el cliente puede retirar, o null si la regla no aplica o está incompleta
    // (una sede sin grupo de horario asignado no muestra ninguna línea).
    public static function fecha_de_regla(array $regla, ?DateTimeImmutable $ahora = null) {
        $ahora = $ahora ?: self::ahora();
        $hoy   = $ahora->setTime(0, 0);

        if (($regla['tipo'] ?? self::TIPO_NINGUNO) === self::TIPO_DIAS) {
            return $hoy->modify('+' . max(0, (int) ($regla['dias'] ?? 0)) . ' days');
        }

        if (($regla['tipo'] ?? self::TIPO_NINGUNO) === self::TIPO_DIA_SEMANA) {
            return self::fecha_dia_semana($regla, $hoy);
        }

        return null;
    }

    // "El viernes de esta semana si la compra entra a tiempo; si no, el de la semana siguiente."
    //
    // El ancla es el día objetivo DE LA SEMANA EN CURSO (semana de lunes a domingo), no el próximo
    // día objetivo del calendario. La diferencia se nota el fin de semana: comprando un sábado,
    // anclando en la semana en curso el retiro cae el viernes 11; anclando en el próximo viernes
    // caería el 18. El cliente confirmó que va lo primero.
    private static function fecha_dia_semana(array $regla, DateTimeImmutable $hoy) {
        $dia_objetivo = (int) ($regla['dia_objetivo'] ?? 0);

        if ($dia_objetivo < 1 || $dia_objetivo > 7) {
            return null;
        }

        $dia_de_hoy = (int) $hoy->format('N');
        $lunes      = $hoy->modify('-' . ($dia_de_hoy - 1) . ' days');
        $fecha      = $lunes->modify('+' . ($dia_objetivo - 1) . ' days');

        $dias_que_alcanzan = array_map('intval', (array) ($regla['dias_alcanzan'] ?? []));

        if (!in_array($dia_de_hoy, $dias_que_alcanzan, true)) {
            $fecha = $fecha->modify('+7 days');
        }

        // Red de seguridad para configuraciones raras: si alguien marca como "día que alcanza" un
        // día posterior al objetivo (objetivo viernes, alcanza sábado), la cuenta daría ayer.
        // Sin esto el checkout mostraría una fecha ya pasada.
        while ($fecha < $hoy) {
            $fecha = $fecha->modify('+7 days');
        }

        return $fecha;
    }

    // Texto final que ve el cliente, ej. "Retira a partir de mañana a las 3:00 pm".
    // Devuelve '' cuando no hay nada que mostrar, y el checkout simplemente no pinta la línea.
    public static function texto_de_regla(array $regla, ?DateTimeImmutable $ahora = null) {
        $ahora     = $ahora ?: self::ahora();
        $plantilla = trim((string) ($regla['plantilla'] ?? ''));
        $fecha     = self::fecha_de_regla($regla, $ahora);

        if (!$fecha || $plantilla === '') {
            return '';
        }

        return strtr($plantilla, [
            '{fecha}' => self::etiqueta_fecha($fecha, $ahora, ($regla['relativo'] ?? '') === '1'),
            '{hora}'  => self::formatear_hora($regla['hora'] ?? ''),
        ]);
    }

    // "hoy" / "mañana" cuando el grupo lo permite y la fecha está a mano; si no, "Viernes 4 de Septiembre".
    //
    // Es una opción del grupo y no algo automático porque las dos redacciones que pidieron son
    // distintas a propósito: las tiendas de Caracas dicen siempre "mañana" y las del interior
    // siempre la fecha escrita, aunque caiga a un día de distancia.
    public static function etiqueta_fecha(DateTimeImmutable $fecha, DateTimeImmutable $ahora, $permitir_relativo) {
        if ($permitir_relativo) {
            $dias_de_diferencia = (int) $ahora->setTime(0, 0)->diff($fecha)->format('%r%a');

            if ($dias_de_diferencia === 0) {
                return __('hoy', 'wc-envios-personalizados');
            }
            if ($dias_de_diferencia === 1) {
                return __('mañana', 'wc-envios-personalizados');
            }
        }

        $dias_semana = self::dias_semana();
        $meses       = self::meses();

        return sprintf(
            // translators: 1: día de la semana, 2: día del mes, 3: mes. Ej. "Viernes 4 de Septiembre".
            __('%1$s %2$d de %3$s', 'wc-envios-personalizados'),
            $dias_semana[(int) $fecha->format('N')],
            (int) $fecha->format('j'),
            $meses[(int) $fecha->format('n')]
        );
    }

    // "15:00" -> "3:00 pm". Formato propio y no date('g:i a') para no depender del ajuste de
    // formato de hora del sitio, que el diseño fija en minúscula y sin cero a la izquierda.
    public static function formatear_hora($hora) {
        $minutos_totales = self::a_minutos($hora);

        $horas   = intdiv($minutos_totales, 60);
        $minutos = $minutos_totales % 60;
        $sufijo  = $horas < 12 ? __('am', 'wc-envios-personalizados') : __('pm', 'wc-envios-personalizados');

        $horas_12 = $horas % 12;
        if ($horas_12 === 0) {
            $horas_12 = 12;
        }

        return sprintf('%d:%02d %s', $horas_12, $minutos, $sufijo);
    }

    // "HH:MM" -> minutos desde medianoche. Fuera de rango se recorta en vez de fallar: los valores
    // vienen de campos del admin y una hora mal tipeada no debe romper el checkout.
    public static function a_minutos($hora) {
        $partes  = explode(':', (string) $hora);
        $horas   = isset($partes[0]) ? (int) $partes[0] : 0;
        $minutos = isset($partes[1]) ? (int) $partes[1] : 0;

        return max(0, min(23, $horas)) * 60 + max(0, min(59, $minutos));
    }

    // --- Franjas de entrega (Delivery) ---------------------------------------------------------

    // De la tabla de franjas configurada en el método, devuelve solo las que aplican a la hora
    // actual. Cada fila declara en qué ventana horaria del día hay que haber hecho el pedido
    // ("desde"/"hasta"), a cuántos días se entrega ("offset") y el rango de entrega.
    public static function franjas(array $filas, ?DateTimeImmutable $ahora = null) {
        $ahora = $ahora ?: self::ahora();
        $hoy   = $ahora->setTime(0, 0);

        $minutos_ahora = (int) $ahora->format('G') * 60 + (int) $ahora->format('i');
        $disponibles   = [];

        foreach ($filas as $fila) {
            $desde = self::a_minutos($fila['desde'] ?? '');
            $hasta = self::a_minutos($fila['hasta'] ?? '');

            if ($minutos_ahora < $desde || $minutos_ahora > $hasta) {
                continue;
            }

            $fecha = $hoy->modify('+' . max(0, (int) ($fila['offset'] ?? 0)) . ' days');

            $disponibles[] = [
                'id'       => self::id_franja($fecha, $fila['inicio'] ?? '', $fila['fin'] ?? ''),
                'etiqueta' => self::etiqueta_franja($fecha, $ahora, $fila['inicio'] ?? '', $fila['fin'] ?? ''),
            ];
        }

        return $disponibles;
    }

    // Identificador estable de una franja. Lleva la fecha adentro a propósito: así, si el cliente
    // elige "hoy de 3 a 6" a las 11:58 am y paga a las 12:05, el id ya no coincide con ninguna de
    // las franjas válidas y la validación lo detecta en vez de aceptar una entrega imposible.
    private static function id_franja(DateTimeImmutable $fecha, $inicio, $fin) {
        return $fecha->format('Y-m-d') . '|' . self::a_minutos($inicio) . '-' . self::a_minutos($fin);
    }

    private static function etiqueta_franja(DateTimeImmutable $fecha, DateTimeImmutable $ahora, $inicio, $fin) {
        return sprintf(
            // translators: 1: día ("Hoy", "Mañana" o una fecha), 2: hora de inicio, 3: hora de fin.
            __('%1$s de %2$s a %3$s', 'wc-envios-personalizados'),
            self::mayuscula_inicial(self::etiqueta_fecha($fecha, $ahora, true)),
            self::formatear_hora($inicio),
            self::formatear_hora($fin)
        );
    }

    // "mañana" -> "Mañana". ucfirst() no sirve si algún día la primera letra es acentuada.
    private static function mayuscula_inicial($texto) {
        if ($texto === '') {
            return $texto;
        }

        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper(mb_substr($texto, 0, 1), 'UTF-8') . mb_substr($texto, 1, null, 'UTF-8');
        }

        return ucfirst($texto);
    }
}

<?php
// Se ejecuta cuando el plugin se borra desde el admin de WordPress (no en una simple desactivación).
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('wcep_version');
delete_option('wcep_nota_feriados_limpiada');

// Las sedes (CPT "tiendas") pertenecen a otro plugin y NO se eliminan aquí.
// Si además quieres borrar los términos de la taxonomía "metodo_envio" al desinstalar, descomenta:
// $terminos = get_terms(['taxonomy' => 'metodo_envio', 'hide_empty' => false]);
// if (!is_wp_error($terminos)) {
//     foreach ($terminos as $termino) {
//         wp_delete_term($termino->term_id, 'metodo_envio');
//     }
// }

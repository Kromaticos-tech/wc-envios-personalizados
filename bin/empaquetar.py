"""Arma el ZIP de distribucion del plugin.

    python bin/empaquetar.py [carpeta_destino]

POR QUE ESTE SCRIPT Y NO Compress-Archive DE POWERSHELL
-------------------------------------------------------
`Compress-Archive` escribe `external_attr` en 0, es decir, no guarda permisos para ninguna
entrada. En Windows da igual, pero al descomprimir en un servidor Linux los archivos quedan sin
permiso de lectura. El sintoma en WordPress es enganoso y costo horas aislarlo:

  - La carpeta del plugin se crea, pero PHP no puede leer la cabecera del archivo principal,
    asi que al activar responde "El plugin no existe".
  - Como el instalador tampoco puede leer la carpeta recien extraida, no detecta el directorio
    raiz del ZIP y nombra la carpeta destino con el nombre del archivo .zip. De ahi salian
    carpetas como `wc-envios-personalizados-1.12.0` en vez de reemplazar la existente.

Este script replica la estructura de un ZIP valido: permisos 0100666, sin entradas de directorio,
todo comprimido con deflate y con la carpeta del plugin como unica raiz.

EL NOMBRE DEL ARCHIVO IMPORTA
-----------------------------
El .zip se llama igual que la carpeta del plugin (`wc-envios-personalizados.zip`). Si lleva la
version en el nombre y el instalador cae en el modo descrito arriba, WordPress crea una carpeta
nueva en lugar de actualizar la que ya existe, y quedan copias duplicadas del plugin.
"""

import io
import os
import re
import sys
import zipfile

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CARPETA = os.path.basename(RAIZ)

# Permisos identicos a los de un ZIP generado por una herramienta estandar: archivo regular, rw.
PERMISOS = 0o100666 << 16


def patrones_excluidos():
    """Lee .distignore, el mismo archivo que documenta que no va en la distribucion."""
    ruta = os.path.join(RAIZ, '.distignore')
    patrones = set()

    if os.path.isfile(ruta):
        for linea in io.open(ruta, encoding='utf-8'):
            linea = linea.strip()
            if linea and not linea.startswith('#'):
                patrones.add(linea)

    # El script y su carpeta tampoco viajan al plugin instalado.
    patrones.add('bin')
    return patrones


def archivos_a_incluir():
    excluidos = patrones_excluidos()
    encontrados = []

    for carpeta, subcarpetas, ficheros in os.walk(RAIZ):
        subcarpetas[:] = sorted(s for s in subcarpetas if s not in excluidos)
        for fichero in sorted(ficheros):
            if fichero in excluidos:
                continue
            absoluto = os.path.join(carpeta, fichero)
            relativo = os.path.relpath(absoluto, RAIZ).replace(os.sep, '/')
            encontrados.append((absoluto, CARPETA + '/' + relativo))

    return encontrados


def version_declarada():
    cabecera = io.open(os.path.join(RAIZ, CARPETA + '.php'), encoding='utf-8').read(2000)
    encontrada = re.search(r'Version:\s*(\S+)', cabecera)
    return encontrada.group(1) if encontrada else '?'


def empaquetar(destino):
    incluidos = archivos_a_incluir()

    with zipfile.ZipFile(destino, 'w', zipfile.ZIP_DEFLATED) as zip_salida:
        for absoluto, interno in incluidos:
            entrada = zipfile.ZipInfo(interno)
            entrada.compress_type = zipfile.ZIP_DEFLATED
            entrada.create_system = 0
            entrada.create_version = 20
            entrada.external_attr = PERMISOS
            zip_salida.writestr(entrada, io.open(absoluto, 'rb').read())

    return len(incluidos)


def verificar(destino):
    """Comprueba lo que de verdad rompe una instalacion, no solo que el archivo exista."""
    zip_hecho = zipfile.ZipFile(destino)
    nombres = zip_hecho.namelist()
    problemas = []

    permisos = set(entrada.external_attr for entrada in zip_hecho.infolist())
    if permisos != {PERMISOS}:
        problemas.append('permisos incorrectos: %s' % [hex(p) for p in permisos])

    if any(nombre.endswith('/') for nombre in nombres):
        problemas.append('hay entradas de directorio')

    raices = set(nombre.split('/')[0] for nombre in nombres)
    if raices != {CARPETA}:
        problemas.append('la raiz no es unica: %s' % sorted(raices))

    principal = CARPETA + '/' + CARPETA + '.php'
    if principal not in nombres:
        problemas.append('falta el archivo principal %s' % principal)

    if os.path.basename(destino) != CARPETA + '.zip':
        problemas.append('el zip deberia llamarse %s.zip' % CARPETA)

    return problemas


if __name__ == '__main__':
    carpeta_destino = sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(RAIZ)
    destino = os.path.join(carpeta_destino, CARPETA + '.zip')

    total = empaquetar(destino)
    problemas = verificar(destino)

    print('%s  (%d archivos, version %s)' % (destino, total, version_declarada()))

    if problemas:
        print('\nPROBLEMAS:')
        for problema in problemas:
            print('  - ' + problema)
        sys.exit(1)

    print('Verificado: permisos, raiz unica, archivo principal y nombre del zip.')

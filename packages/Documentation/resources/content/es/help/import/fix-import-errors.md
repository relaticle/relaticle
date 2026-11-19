---
title: Corrija errores de importación
description: Qué hacer cuando falla un paso de carga, asignación o validación, y cómo se tratan las fechas ambiguas.
order: 4
updated: "2026-08-13"
related: [help/import/prepare-your-csv, help/import/update-existing-records]
---

La mayoría de los problemas de importación son de uno de cuatro tipos: el
archivo no se carga, una columna no se asigna, los valores no pasan la
validación o la importación misma se comporta mal. Busque el síntoma más abajo.
Cada solución es algo que puede hacer usted mismo.

## Problemas de carga

| Problema | Solución |
|---------|----------|
| El archivo es demasiado grande | Divídalo en archivos de menos de 10,000 filas cada uno |
| Formato no válido | Vuelva a guardarlo como "CSV UTF-8" desde Excel |
| La carga falla | Revise su conexión y pruebe con un archivo más pequeño |

## Problemas de asignación

| Problema | Solución |
|---------|----------|
| Una columna no se asignó automáticamente | Elija usted mismo el campo en el menú desplegable **Asignar a** |
| Falta un atributo personalizado en el menú desplegable | Revise el código del campo en **Configuración → Atributos personalizados** |
| Un campo obligatorio aparece en rojo | Asígnele una de las columnas de su CSV |

## Problemas de validación

| Problema | Solución |
|---------|----------|
| Correo electrónico no válido | Corrija el formato: `user@domain.com` |
| Fecha no válida | Use el formato ISO, europeo o estadounidense de manera uniforme |
| ID de registro no válido | Vuelva a exportar para obtener IDs nuevos |
| Opción de selección desconocida | Use la etiqueta exacta de la opción tal como aparece en los ajustes del campo |

En el paso de revisión también puede corregir un valor una sola vez (**Corregir este valor**)
para que se corrija en todas las filas que lo tienen, u omitirlo (**Omitir**). Las
filas se importan igual, con ese campo vacío.

## Fechas ambiguas

El asistente reconoce tres formatos de fecha y detecta cuál usa cada columna:

| Formato | Patrón | Ejemplo |
|--------|---------|---------|
| ISO | YYYY-MM-DD | `2024-05-15` |
| Europeo | DD/MM/YYYY | `15/05/2024` |
| Estadounidense | MM/DD/YYYY | `05/15/2024` |

Cuando el día es mayor que 12, el formato no deja dudas: `31/01/2024` solo puede
ser europeo. Cuando ambas posiciones son 12 o menos (`01/02/2024`, ¿2 de enero o
1 de febrero?), el asistente muestra una advertencia y un menú desplegable de
formato en esa columna. Elija **Europeo** o **Estadounidense** para indicar
qué lectura es la correcta. Las fechas ISO nunca son ambiguas, y por eso son el
formato más seguro para exportar desde otras herramientas.

## Problemas de importación

| Problema | Solución |
|---------|----------|
| Se queda en «Procesando…» | Las importaciones grandes se ejecutan en segundo plano. Vuelva en unos minutos |
| Algunas filas fallaron | Descargue las filas fallidas, corríjalas y vuelva a importar solo esas |
| Duplicados inesperados | Asigne una columna única (correo electrónico, dominio o ID). Consulte [Actualice registros con una importación](/es/help/import/update-existing-records) |

## Conviene saber

- **No hay forma de deshacer.** Pruebe con una muestra de 5 a 10 filas antes de
  importar el archivo completo.
- **Una importación fallida conserva las filas que sí se importaron.** Corrija y
  vuelva a importar solo las filas fallidas que descargó.
- **Un tipo de registro por archivo.** Al importar personas, igualmente se pueden
  crear sus empresas automáticamente si relaciona la empresa por Nombre.
- **Cada ejecución queda registrada.** La página **Historial de importaciones**,
  bajo el nombre de su espacio de trabajo, muestra los totales de registros
  creados, actualizados, omitidos y fallidos de cada importación.

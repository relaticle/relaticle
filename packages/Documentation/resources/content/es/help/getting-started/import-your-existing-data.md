---
title: Importe sus datos existentes
description: Pasos para importar un CSV en Relaticle, asignar columnas y revisar registros, con límites de 10,000 filas y 10 MB por archivo.
order: 4
updated: "2026-08-12"
related: [help/import/prepare-your-csv, help/import/update-existing-records, help/getting-started/use-custom-fields]
---

La importación trae a Relaticle empresas, personas u oportunidades que ya tiene
en una hoja de cálculo, en lugar de escribirlas una por una. Cargue un CSV,
asigne sus columnas a los campos de Relaticle, revise los valores y confirme.
Relaticle le muestra exactamente cuántos registros se crearán o actualizarán
antes de que algo cambie.

1. Haga clic en **Empresas**, **Personas** u **Oportunidades** en la barra
   lateral.
2. Haga clic en **Importar / Exportar** y luego en **Importar empresas** (la
   etiqueta cambia según la lista en la que esté).
3. Haga clic en **Elegir un archivo CSV**, o suelte el archivo en el área de carga.
   El límite es de 10,000 filas y 10 MB.
4. Haga clic en **Continuar**.

## Asigne sus columnas

Relaticle asigna automáticamente las columnas de su archivo a los campos de
Relaticle cuando los nombres se parecen lo suficiente.

![El paso «Asignar», con los valores de columna Name, Domain y LinkedIn asignados automáticamente a los campos Name, Domains y LinkedIn](/help-assets/getting-started/import-your-existing-data-1.png)

5. Revise cada campo en **Asignar a** y corrija los que se asignaron mal.
6. Haga clic en **Continuar**.

## Revise y corrija valores

Relaticle valida cada valor único de cada columna y los agrupa en **Todos**,
**Requiere revisión**, **Modificados** y **Omitidos**. Edite un valor en la misma
fila para corregirlo, u omita una fila que no quiera importar.

7. Haga clic en **Continuar** cuando esté conforme con los valores asignados.
8. Revise el número de registros que se crearán y actualizarán, haga clic en
   **Iniciar importación** y confirme.

![El resumen «Importación completada» con 2 creados, 0 actualizados y 0 fallidos](/help-assets/getting-started/import-your-existing-data-2.png)

Todas las importaciones anteriores aparecen en la página **Historial de importaciones**,
bajo el nombre de su espacio de trabajo, con la cantidad de filas creadas,
actualizadas, omitidas o con error.

## Si una columna no se asigna automáticamente, asígnela a mano

Abra el menú desplegable de **Asignar a** para esa columna y elija usted mismo el
campo correcto, o haga clic en **Quitar asignación** para dejar esa columna fuera de
la importación.

Para conocer las reglas de formato de archivo, cómo encontrar y actualizar
registros existentes y cómo resolver los valores que marca el asistente, consulte
los artículos de [Importación de datos](/es/help/import).

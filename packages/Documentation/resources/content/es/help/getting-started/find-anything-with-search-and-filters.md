---
title: Encuentre todo con búsqueda y filtros
description: Aprenda a buscar registros en Relaticle y a filtrar listas de empresas, personas, oportunidades, tareas y notas.
order: 7
updated: "2026-11-13"
related: [help/getting-started/use-custom-fields, help/getting-started/import-your-existing-data]
---

Presione **⌘K**, o haga clic en el cuadro de búsqueda de la barra lateral, para
buscar en todos los registros a la vez. Use la fila de filtros de cualquier
lista para acotar lo que ya tiene a la vista.

1. Haga clic en el cuadro de búsqueda en la parte superior de la barra lateral,
   o presione **⌘K**.
2. Escriba cualquier parte de un nombre. Los resultados aparecen agrupados por
   tipo de registro: **Empresas**, **Oportunidades** y más.
3. Haga clic en un resultado para abrirlo.

![El menú de búsqueda global con resultados para "Acme" agrupados en Empresas y Oportunidades](/help-assets/getting-started/find-anything-with-search-and-filters-1.png)

## Encuentre un registro en una lista

Las listas (Empresas, Personas, Oportunidades, Tareas y Notas) no tienen cuadro
de búsqueda. Para encontrar un registro por nombre en una lista, agregue un
filtro sobre su campo de nombre o título con la condición **contiene**. La fila
de filtros sobre la tabla lo muestra, y desde ahí puede quitarlo.

## Filtre una lista

1. Haga clic en **Filtrar** en la fila sobre la tabla. Cuando ya hay un filtro
   aplicado, el botón se muestra como **+**.
2. Elija un campo. Escriba para acotar la lista de campos.
3. Elija una condición, como **contiene**, **es cualquiera de** o **hace menos de**.
4. Ingrese o elija un valor. La lista se actualiza cuando el filtro está
   completo.

![La fila de filtros sobre la lista de Oportunidades con tres chips: Etapa es cualquiera de Negociación/Revisión, Fecha de cierre en los próximos 30 días y Monto mayor que $10,000.00](/help-assets/getting-started/find-anything-with-search-and-filters-2.png)

Cada filtro se muestra como un chip en la fila. Haga clic en su campo, su
condición o su valor para cambiarlo. Si elige otro campo, el chip se conserva y
se borran su condición y su valor. Para quitarlo, haga clic en **⋮** en el chip y
luego en **Quitar filtro**. Un registro debe cumplir todos los chips para
seguir en la lista. Cuando los filtros ocultan todos los registros, haga clic
en **Borrar filtros**.

La fila se mantiene en una sola línea. Desplácela hacia los lados cuando tenga
más chips de los que caben.

## Use fechas que se mantienen relativas

Elija **hace menos de**, **en menos de** o **cae** para filtrar por un período,
como los últimos 30 días o este mes. Una vista guardada mantiene el período
relativo, así que avanza con el calendario. **Hoy** funciona de la misma forma.

Un campo que contiene miembros le muestra primero a usted, con su nombre seguido
de **(yo)**.

## Muestre los registros eliminados

**Registros eliminados** aparece al final de la lista de campos. Su chip muestra
los registros eliminados junto con el resto (**incluidos**) o por separado
(**solo**). Haga clic en la segunda parte del chip para cambiar. Solo lo ve si
tiene permiso para ver los registros eliminados.

## Ordene y agrupe una lista

Haga clic en **Ordenar** y busque la columna por la que quiere ordenar la lista.
Haga clic en **Agregar orden** para desempatar con otra columna, y arrastre una
fila por su asa para cambiar cuál orden va primero. Cada fila alterna entre
ascendente y descendente, y su X la quita. El botón muestra **Ordenado por**
y la primera columna, más un contador cuando hay otras a continuación.

En Tareas, **Agrupar** agrupa la lista por estado o prioridad.

Una vista guardada es solo suya y se guarda sola. Cambie un filtro, un orden,
una agrupación, una columna o el nombre de una columna mientras hay una vista
abierta, y la vista conserva el cambio. Para crear otra vista a partir de lo que
ve en pantalla, abra el selector de vistas y elija **Crear vista nueva**.

El selector muestra sus vistas en un solo orden. La tabla integrada es una de las
filas, como **Lista completa de empresas**. Oportunidades agrega **Pipeline**,
Tareas agrega **Tablero** y Notas agrega **Tarjetas**. La búsqueda en la parte
superior acota la lista por nombre.

Arrastre una fila para moverla. En una pantalla táctil, mantener presionado un
momento hace lo mismo. El menú de cada fila también tiene **Subir** y **Bajar**,
que funcionan desde el teclado. La búsqueda desactiva el arrastre y el movimiento
mientras escribe.

La vista que está en la parte superior es con la que se abre una página cuando
la visita sin un enlace, por ejemplo desde la barra lateral. Si arriba hay un
tablero o la vista de tarjetas, esa es la que abre la página. Un enlace que
nombra algo, como un filtro, un registro o **Lista completa de empresas**, abre
lo que señala.

## Si no encuentra un campo en la lista de filtros

Se puede filtrar por todos los atributos personalizados, salvo los de tipo Área
de texto, Editor de texto enriquecido y Selector de color. Tampoco se pueden
filtrar los atributos cifrados ni los que están desactivados. Un atributo no
necesita **Visible en la lista** para poder filtrarse. Consulte
[Use atributos personalizados](/es/help/getting-started/use-custom-fields) para
conocer todos los ajustes de un atributo.

## Filtre desde la API, MCP o Rela

La fila de filtros es una forma de acotar una lista. La API REST, el MCP server y
Rela, el asistente de IA integrado, usan un objeto `filter` en su lugar. Filtra
por campos nativos y atributos personalizados, combina condiciones con AND, OR y
NOT, y sigue los vínculos entre registros. Lea [la guía de MCP](/es/developers/mcp)
para conocer la gramática completa.

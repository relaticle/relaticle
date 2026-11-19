---
title: Agregar, editar y archivar atributos
description: Cree atributos en la página Atributos personalizados, reordénelos, retírelos sin riesgo y conozca los atributos del sistema.
order: 2
updated: "2026-11-10"
related: [help/custom-fields/field-types-you-can-add, help/custom-fields/control-where-a-field-appears, help/getting-started/use-custom-fields]
---

Los atributos se administran en la página **Atributos personalizados**: haga clic
en el nombre de su espacio de trabajo en la parte superior de la barra lateral y
elija **Atributos personalizados**. Solo el propietario del espacio de trabajo y
los administradores ven esta página. Todos los demás usan los atributos. Los
propietarios y administradores les dan forma.

La página muestra una pestaña por tipo de registro (Empresas, Personas,
Oportunidades, Tareas, Notas) con una insignia de conteo, y los atributos del
tipo seleccionado en una tabla en la que puede buscar con **Buscar atributos...**.

![La página Atributos personalizados en la pestaña Oportunidades, con Amount, Close Date y Stage con insignias System y un atributo personalizado Deal Source](/help-assets/custom-fields/add-and-manage-fields-1.png)

## Agregar un atributo

1. Elija la pestaña del tipo de registro.
2. Haga clic en **Agregar atributo**.
3. Elija el **Tipo**. Es permanente, así que revise antes
   [Tipos de atributo que puede agregar](/es/help/custom-fields/field-types-you-can-add).
4. Póngale un nombre (los nombres deben ser únicos por tipo de registro).
5. En los tipos de selección, agregue las **Opciones**.
6. Ajuste los interruptores de **Ajustes** (dónde aparece el atributo y si está
   cifrado) y guarde.

El atributo aparece de inmediato en el formulario del registro, en la página del
registro y (si lo activó) en la vista de lista, para todos en el espacio de
trabajo. También lo pueden usar al instante Rela y la importación de CSV, sin
ninguna configuración adicional.

## Editar y reordenar

Haga clic en la fila de un atributo para renombrarlo, cambiar sus opciones o modificar sus ajustes: todo menos el tipo. Arrastre el control al inicio de cada
fila para reordenar los atributos. Los formularios y las páginas de registro
siguen el orden que defina.

## Archivar en lugar de eliminar

**Desactivar** mueve un atributo al grupo plegable **Archivado** al final de la
tabla. Desaparece de los formularios y las listas, pero se conservan todos los
valores almacenados. **Activar** devuelve el atributo y sus datos de inmediato.
Es la forma segura de retirar un atributo que podría necesitar de nuevo, y la
forma de ocultar un atributo de Rela.

**Eliminar** solo está disponible en un atributo que ya está archivado, y es
destructivo: el atributo, sus opciones y **todos los valores almacenados en sus
registros** se eliminan de forma permanente. Relaticle le pide confirmación
antes.

## Atributos del sistema

Los atributos con una insignia **Sistema** son los que el propio Relaticle
necesita: los Correos electrónicos de una persona, los Dominios de una empresa,
el Monto, la Fecha de cierre y la Etapa de una oportunidad, el Estado y la
Prioridad de una tarea, el Contenido de una nota. No puede renombrarlos, cambiar
su tipo, archivarlos ni eliminarlos, pero sí puede editar sus ajustes y, en las
selecciones entre ellos, sus opciones:
[renombre las etapas de su pipeline](/es/help/custom-fields/edit-the-options-in-a-select-field)
cuando quiera.

---
title: Fusionar registros duplicados
description: Combine dos empresas o personas duplicadas en un registro. Gana el de la derecha. La fusión no se puede deshacer.
order: 7
updated: "2026-11-20"
related: [help/records/company-records, help/records/people-records, help/records/restore-deleted-records]
---

Los duplicados aparecen casi siempre tras una importación o una sincronización del
buzón. Al fusionar dos empresas, o dos personas, obtiene un único registro que conserva
todo lo vinculado a cualquiera de los dos. Los miembros y los administradores pueden
fusionar. Los lectores no.

## Iniciar una fusión

- **Desde el aviso de duplicados.** La página de un registro con posibles duplicados
  muestra una sección con su número. Despliéguela y haga clic en **Fusionar** junto al
  registro que quiera. El cuadro de diálogo se abre con ese registro ya elegido.
- **Desde el menú.** Abra el registro, haga clic en el menú **⋯** y elija **Fusionar
  registro**.

## En el cuadro de diálogo

1. Busque el otro registro por nombre, dominio o correo electrónico y elíjalo.
2. Revise las dos tarjetas. Con **Intercambiar registros** cambia cuál gana.
3. Haga clic en **Fusionar registros**.

El registro de la derecha gana siempre que ambos tengan un valor. Los huecos se
completan con el otro registro. Los dominios, los correos electrónicos y los valores de
selección múltiple se combinan, primero los del ganador.

## Qué obtiene

La fusión crea un registro nuevo. Los dos originales se retiran y todo lo vinculado a
cualquiera de ellos pasa al nuevo: notas, tareas, correos, reuniones, personas y
oportunidades, y el historial de actividad. El historial muestra una sola creación y una
entrada que indica quién fusionó los registros.

Una lista que supera su límite conserva todos sus valores. La fusión sube el límite del
espacio de trabajo para ese atributo personalizado hasta el número combinado, con un
máximo de 20. Después, cada registro de ese tipo puede contener tantos valores.

## Enlaces antiguos

El enlace de un registro fusionado muestra **Este registro se fusionó** y un botón **Ir
al registro** que lleva al registro nuevo.

## Fusionar desde la API, MCP y Rela

Puede fusionar sin el cuadro de diálogo. La API REST tiene un punto de conexión de
fusión para empresas y otro para personas, el servidor MCP ofrece una herramienta para
cada una, y Rela, el asistente de IA integrado, propone fusiones para que usted las
apruebe. Se aplica la misma regla que en el cuadro de diálogo.

## Conviene saber

- Una fusión no se puede deshacer. Un registro fusionado no está en la papelera y no se
  puede [restaurar](/es/help/records/restore-deleted-records).
- Una fusión admite dos registros. Tres duplicados requieren dos fusiones.
- Solo se pueden fusionar empresas y personas.

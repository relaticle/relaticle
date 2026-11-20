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

- **Desde la sección de posibles duplicados.** La página de un registro con posibles
  duplicados muestra una sección con su número. Despliéguela y haga clic en **Fusionar**
  junto al registro que quiera. El cuadro de diálogo se abre con ese registro ya elegido.
- **Desde el menú.** Abra el registro, haga clic en el menú **⋯** y elija **Fusionar
  registro**.

## En el cuadro de diálogo

1. Busque el otro registro por nombre, dominio o correo electrónico y elíjalo.
2. Revise las dos tarjetas. Con el botón **⇄** entre ellas cambia cuál gana.
3. Haga clic en **Fusionar registros**.

El registro de la derecha gana siempre que ambos tengan un valor. Los huecos se
completan con el otro registro. Los dominios, los correos electrónicos y cualquier
atributo que contenga una lista se combinan, primero los del ganador. Eso incluye la
selección múltiple, la lista de casillas, las etiquetas y los atributos con la opción
**Permitir varios valores** activada.

## Qué obtiene

La fusión crea un registro nuevo. Los dos originales se retiran. Todo lo vinculado a
cualquiera de ellos pasa al nuevo: notas, tareas, correos, reuniones, personas y
oportunidades, y el historial de actividad. El historial muestra una sola creación y una
entrada que indica quién fusionó los registros.

Si una lista combinada supera el límite del campo, el límite sube hasta el número
combinado. Esto vale para todo el espacio de trabajo, así que cada registro de ese tipo
puede contener tantos valores. Se rechaza una fusión cuya lista combinada pasaría de 20.
El cuadro de diálogo le pide que elimine antes algunos valores.

## Enlaces antiguos

El enlace de un registro fusionado muestra **Este registro se fusionó** y un botón **Ir
al registro** que lleva al registro nuevo.

## Fusionar desde la API, MCP y Rela

Puede fusionar sin el cuadro de diálogo. La API REST tiene un punto de conexión de
fusión para empresas y otro para personas. El servidor MCP ofrece una herramienta para
cada una. Rela, el asistente de IA integrado, propone fusiones para que usted las
apruebe. En todos los casos se aplica la misma regla que en el cuadro de diálogo.

## Conviene saber

- Una fusión no se puede deshacer. Un registro fusionado no está en la papelera y no se
  puede [restaurar](/es/help/records/restore-deleted-records).
- Una fusión admite dos registros. Tres duplicados requieren dos fusiones.
- Solo se pueden fusionar empresas y personas.

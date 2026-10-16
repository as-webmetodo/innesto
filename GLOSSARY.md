# Innesto

A suite of dynamic micro-modules grafted onto static sites, running on shared PHP hosting deployed via FTP.

## Language

### The suite

**Innesto Roots**:
The core uploaded once to the server, on which every Module depends.
_Avoid_: Core, hub, Roots module

**Module**:
A vertical micro-tool installed into Innesto Roots, delivering one dynamic feature to a site.
_Avoid_: Package, plugin, extension

**Package id**:
A Module's machine identifier, namespaced `in.<name>` (e.g. `in.hours`), used in code, URLs and configuration.
_Avoid_: Slug, module key

**Display name**:
A Module's human-facing name, always "Innesto" followed by an Italian word (e.g. Innesto Orario).

### Modules

**Innesto Modulo** (`in.modulo`):
The form mailer Module.
_Avoid_: Mail, form module

**Innesto Agenda** (`in.agenda`):
The booking and availability calendar Module.
_Avoid_: Book, calendar module

**Innesto Orario** (`in.hours`):
The opening hours Module.
_Avoid_: Hours (as a display name), `in.orario`

### Opening hours (Innesto Orario)

**Schedule**:
A named, independent set of opening hours for one place or service (e.g. "Kitchen", "Via Roma shop"); an install may hold several.
_Avoid_: Timetable, calendar, hours set

**Opening period**:
A single span of time during which a Schedule is open, belonging to the day it starts even when it ends after midnight.
_Avoid_: Shift, slot, time range

**Weekly hours**:
The regular Opening periods of a Schedule for each weekday; a weekday without periods is closed.
_Avoid_: Default hours, standard hours

**Season**:
An alternative set of Weekly hours that applies over a date span, optionally repeating every year (e.g. summer hours).
_Avoid_: Period, summer schedule

**Exception**:
A date span whose hours replace the Schedule's hours entirely for those dates, optionally repeating every year.
_Avoid_: Override, holiday

**Closure**:
An Exception with no Opening periods; it may have no end date ("closed until further notice").
_Avoid_: Holiday, day off

**Special hours**:
An Exception with Opening periods that differ from the usual ones.
_Avoid_: Extraordinary hours, holiday hours

**Schedule timezone**:
The timezone whose local wall-clock time all of a Schedule's times are expressed in.
_Avoid_: Visitor timezone

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

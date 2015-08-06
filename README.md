# Work-Flowed Enterprise Resource Planner

The acronim **prefw** is the reverse of **wferp**.

This is a work flow system with extensive business module components.

## Development Notes

1. Work flow architecture

    - CRUD work flow containers
    - CRUD instance of work flow components
    - Insert and re-order components inside containers
    - Defines its own JSON-Schema that sustains the whole progress

1. Work flow components

    - Options interface should be built upon JSON-Schema and schema-form.<br />
      <sup>*i.e. Input from what properties, output to what properties.*</sup>
    - Has data schema optionally derived from the container
    - Has its own form schema for interface purpose
    - Serves form schema for interface purpose

1. Functional Modules

    - Modules will serve as work flow components in the system
    - Modules should provide component schemas for interface
    - Modules inject hooks into component modification process (pre/post save/delete)<br />
      <sup>*Note: Only do processing works, validations should be done by JSON-Schemas.*</sup>
    - Modules define components

1. User/Role

    - Each component instance
    - Support read-only access
    - Users automatically has read access to container when one of its component is accessible
    - Container modification is a separated permission
    - Once the previous parts are finished, module codes can be written in the interface.<br />
      <sup>*Note: Requires write permission to the corresponding module*</sup>

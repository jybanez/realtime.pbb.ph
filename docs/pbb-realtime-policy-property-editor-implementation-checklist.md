# PBB Realtime Policy Property Editor Checklist

Goal:
- replace policy JSON textarea editing with a Helper-based typed property editor
- keep backend persistence contract unchanged
- keep advanced JSON as a fallback, not the primary editing path

Checklist:
- [ ] Refresh vendored Helper copy to the version that includes `ui.property.editor`
- [ ] Load `ui.property.editor` in the admin shell runtime
- [ ] Define client-side section builders for:
  - capability profile
  - room policy profile
  - rate limit profile
  - session limit profile
  - attachment transport policy
- [ ] Map existing policy objects into property-editor section data
- [ ] Replace hidden textarea-first policy modal flow with a property-editor-backed flow
- [ ] Keep core policy identity fields visible above the property editor
- [ ] Add numeric and enum validation on the client side
- [ ] Serialize edited property values back into the current structured policy payload
- [ ] Keep server-side normalization and validation in `PolicyController`
- [ ] Add an `Advanced JSON` fallback surface for power users
- [ ] Update policy detail page language to match the new editor model
- [ ] Add tests for:
  - property-editor-backed policy create/update
  - structured serialization
  - advanced JSON fallback
- [ ] Update docs/specs so policy administration no longer assumes textarea JSON editing

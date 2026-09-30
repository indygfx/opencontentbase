# Planning: Writing app (projects & sharing)

This document records the architecture decisions **before** any writing app
code is written. It is the binding framework for phase 4 of the roadmap.
When changing any of these decisions: update this document first, then build.

## 1. Core model: visibility belongs to the project, not the user

Every account owns their projects. A project (e.g. a novel) bundles all
associated texts: chapters, characters, relationships, backgrounds.

```
User 1 --owns--> Project A --bundles--> chapters, characters, ...
User 1 --owns--> Project B
User 2 --owns--> Project C

Sharing: User 1 shares Project A with User 2
```

Core rule:

> **Visibility is a property of the project.**
> A user sees an object exactly when they own the project OR are on the
> project's share list.

There are no per-object grants for individual chapters or characters.
Sharing always happens at the project level. (Cascade: whoever may access
the project may access all of its texts.)

## 2. Data model (from day one, even where the UI comes later)

### Writing app tables (module migration, not core)

```sql
projects (
    id TEXT PRIMARY KEY,
    object_id TEXT REFERENCES content_objects(id),  -- novel as content object
    owner_id TEXT NOT NULL REFERENCES users(id),
    title TEXT NOT NULL,
    created_at ...
)

project_members (
    project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role TEXT NOT NULL DEFAULT 'member' CHECK (role IN ('owner', 'member')),
    PRIMARY KEY (project_id, user_id)
)
```

**Why create `project_members` now, even though version 1 only has the
owner:** every read and write query goes through this table from day 1
(in version 1 simply with an empty member list). "Sharing" later becomes
a table row, not a rewrite. No place in the code checks visibility without
querying this table.

### Project membership on every text type

Every detail table of the writing app (chapters, characters, relationships,
backgrounds) must have:

```sql
project_id TEXT NOT NULL REFERENCES projects(id) ON DELETE CASCADE
```

### Slug uniqueness: per project, not global

The chapter "chapter-1" will occur in every novel. Therefore:

```sql
UNIQUE (project_id, slug)
```

instead of today's global uniqueness per type. This must be decided **now**,
because links (`[[type:slug]]`) live permanently inside the text.

**Link syntax (decision):** inside a project the short link
`[[chapter:chapter-1]]` is enough and is resolved in the context of the
current project. Cross-project links (later, rare):
`[[chapter:project/chapter-1]]`.

For this, `ModuleInterface::resolveLink()`/`resolveLinks()` must be able to
receive the project context (core extension before the writing app is
built – otherwise the module builds past the core).

## 3. One central visibility check

There is **exactly one function** in the writing app module answering:

> "May user X see project Y?" / "...and edit it?"

```php
canAccess(User $user, string $projectId): bool      // see
canEdit(User $user, string $projectId): bool       // collaborate
isOwner(User $user, string $projectId): bool        // delete/manage
```

All lists (project overview, chapter list, character list) and all detail
views call ONLY these functions. Nowhere does raw SQL contain a
hand-built visibility condition.
Reason: distributed checks guarantee a forgotten spot – and every forgotten
spot is a security hole (other people's novels readable).

## 4. Roles: global vs. project-local

Two separate levels, never mixed:

| Level | Meaning | Examples |
|---|---|---|
| Global role (admin/editor/user) | System-wide rights | manage pages, create users (admin) |
| Project role (owner/member) | Rights on one concrete project | read, write, delete project |

**Version 1 deliberately only two project roles:**
- `owner`: everything – manage, share, delete
- `member`: read all texts in the project and create/edit them

Fine-grained levels ("read only", "only own chapters") come later; the
table can already hold them without a rewrite.

## 5. Deletion rules (fixed before the first delete button)

- **Delete project:** only `owner`. Cascades everything (chapters,
  characters, revisions, shares). Members lose access – the owner decides.
- **Delete object inside a project (chapter/character/...):**
  every `member` may delete their own objects (`content_objects.owner_id`
  exists in the core); objects of others only as `owner`.
- **Revoke a project share:** only `owner`.

## 6. Overview screen

The writing app's home screen is ONE query (UNION):

```
my projects (owner_id = me)
UNION shared projects (project_members.user_id = me)
```

Sorting, filtering, covers etc. are UI details on this single query.

## 7. Separation from the core

- "Project", "sharing", "visibility" are **writing app concepts** and live
  in the module (own migrations, own checks). The core stays lean:
  content_objects, revisions, role guard, registry.
- The only core change needed in advance: link resolution with project
  context (see section 2).
- If the pages module needs sharing later too, the concept is lifted into
  the core **then** – not earlier.

## 8. Build order

1. **Admin area "user management"** (create, set role, deactivate):
   prerequisite for "share with a specific user" and for test users.
2. **Writing app foundation:** `projects` + `project_members` + the three
   check functions + project overview ("mine + shared").
   Extend core link resolution with project context.
3. **Text types** (chapters -> characters -> relationships -> backgrounds):
   all run through the same check functions.
4. **Sharing UI** ("for everyone / for specific users"): just a form
   on top of the long-existing logic.

## 9. Deliberately open questions (postponed on purpose)

- Project covers, description texts, tags (UI detail, can be added anytime)
- "Share with everyone" as a third sharing mode besides individual users
- Per-project change history (revisions exist per object; a cross-object
  project chronicle is its own feature)
- Export (epub/PDF) – separate module topic

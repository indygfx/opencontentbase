import { Editor } from '@tiptap/core'
import { StarterKit } from '@tiptap/starter-kit'
import { Highlight } from '@tiptap/extension-highlight'
import { TableKit } from '@tiptap/extension-table'
import { TaskList, TaskItem } from '@tiptap/extension-list'
import { Placeholder, CharacterCount } from '@tiptap/extensions'
import { Markdown } from '@tiptap/markdown'

/**
 * WYSIWYG-style Markdown editor (Tiptap) for ContentBase.
 *
 * The server renders forms with a plain <textarea> that holds Markdown.
 * When JavaScript is available, each textarea marked with
 * data-editor="tiptap" is progressively enhanced:
 *
 *  - the textarea is hidden (but stays the real form field)
 *  - a Tiptap editor is mounted next to it, loading the textarea content
 *    as Markdown (contentType: 'markdown')
 *  - on every update and on form submit, editor.getMarkdown() is written
 *    back into the textarea, so the server flow (CSRF, validation,
 *    Markdown storage) is completely unchanged
 *  - if JavaScript is unavailable, the plain textarea keeps working
 */

const ICONS = {
  undo: 'M20 13.5C20 17.09 17.09 20 13.5 20H6V18H13.5C16 18 18 16 18 13.5S16 9 13.5 9H7.83L10.91 12.09L9.5 13.5L4 8L9.5 2.5L10.92 3.91L7.83 7H13.5C17.09 7 20 9.91 20 13.5Z',
  redo: 'M10.5 18H18V20H10.5C6.91 20 4 17.09 4 13.5S6.91 7 10.5 7H16.17L13.08 3.91L14.5 2.5L20 8L14.5 13.5L13.09 12.09L16.17 9H10.5C8 9 6 11 6 13.5S8 18 10.5 18Z',
  heading: 'M2 4V7H7V19H10V7H15V4H2M21 9H12V12H15V19H18V12H21V9Z',
  bold: 'M13.5,15.5H10V12.5H13.5A1.5,1.5 0 0,1 15,14A1.5,1.5 0 0,1 13.5,15.5M10,6.5H13A1.5,1.5 0 0,1 14.5,8A1.5,1.5 0 0,1 13,9.5H10M15.6,10.79C16.57,10.11 17.25,9 17.25,8C17.25,5.74 15.5,4 13.25,4H7V18H14.04C16.14,18 17.75,16.3 17.75,14.21C17.75,12.69 16.89,11.39 15.6,10.79Z',
  italic: 'M10,4V7H12.21L8.79,15H6V18H14V15H11.79L15.21,7H18V4H10Z',
  underline: 'M5,21H19V19H5V21M12,17A6,6 0 0,0 18,11V3H15.5V11A3.5,3.5 0 0,1 12,14.5A3.5,3.5 0 0,1 8.5,11V3H6V11A6,6 0 0,0 12,17Z',
  strikethrough: 'M3,14H21V12H3M5,4V7H10V10H14V7H19V4M10,19H14V16H10V19Z',
  highlight: 'M4,17L6.75,14.25L6.72,14.23C6.14,13.64 6.14,12.69 6.72,12.11L11.46,7.37L15.7,11.61L10.96,16.35C10.39,16.93 9.46,16.93 8.87,16.37L8.24,17H4M15.91,2.91C16.5,2.33 17.45,2.33 18.03,2.91L20.16,5.03C20.74,5.62 20.74,6.57 20.16,7.16L16.86,10.45L12.62,6.21L15.91,2.91Z',
  list: 'M7,5H21V7H7V5M7,13V11H21V13H7M4,4.5A1.5,1.5 0 0,1 5.5,6A1.5,1.5 0 0,1 4,7.5A1.5,1.5 0 0,1 2.5,6A1.5,1.5 0 0,1 4,4.5M4,10.5A1.5,1.5 0 0,1 5.5,12A1.5,1.5 0 0,1 4,13.5A1.5,1.5 0 0,1 2.5,12A1.5,1.5 0 0,1 4,10.5M7,19V17H21V19H7M4,16.5A1.5,1.5 0 0,1 5.5,18A1.5,1.5 0 0,1 4,19.5A1.5,1.5 0 0,1 2.5,18A1.5,1.5 0 0,1 4,16.5Z',
  blocks: 'M15,4V6H18V18H15V20H20V4M4,4V20H9V18H6V6H9V4H4Z',
  table: 'M5,4H19A2,2 0 0,1 21,6V18A2,2 0 0,1 19,20H5A2,2 0 0,1 3,18V6A2,2 0 0,1 5,4M5,8V12H11V8H5M13,8V12H19V8H13M5,14V18H11V14H5M13,14V18H19V14H13Z',
  link: 'M3.9,12C3.9,10.29 5.29,8.9 7,8.9H11V7H7A5,5 0 0,0 2,12A5,5 0 0,0 7,17H11V15.1H7C5.29,15.1 3.9,13.71 3.9,12M8,13H16V11H8V13M17,7H13V8.9H17C18.71,8.9 20.1,10.29 20.1,12C20.1,13.71 18.71,15.1 17,15.1H13V17H17A5,5 0 0,0 22,12A5,5 0 0,0 17,7Z',
}

function svgIcon(path) {
  return '<svg fill="currentColor" width="20" height="20" viewBox="0 0 24 24"><path d="' + path + '"></path></svg>'
}

function makeButton(opts) {
  const btn = document.createElement('button')
  btn.type = 'button'
  btn.className = 'editor-toolbar-btn'
  btn.title = opts.title
  btn.setAttribute('aria-label', opts.label)
  if (opts.keyshortcuts) {
    btn.setAttribute('aria-keyshortcuts', opts.keyshortcuts)
  }
  if (opts.icon) {
    btn.innerHTML = svgIcon(ICONS[opts.icon])
  } else if (opts.text) {
    btn.textContent = opts.text
  }
  if (opts.pressed !== undefined) {
    btn.setAttribute('aria-pressed', String(opts.pressed))
  }
  return btn
}

const editorRef = { current: null }

const HEADING_ITEMS = [
  { cmd: 'p', label: 'Paragraph', text: 'Paragraph', check: (e) => e.isActive('paragraph') },
  { cmd: 'h1', label: 'Heading 1', text: 'Heading 1', check: (e) => e.isActive('heading', { level: 1 }) },
  { cmd: 'h2', label: 'Heading 2', text: 'Heading 2', check: (e) => e.isActive('heading', { level: 2 }) },
  { cmd: 'h3', label: 'Heading 3', text: 'Heading 3', check: (e) => e.isActive('heading', { level: 3 }) },
]

const HEADING_CMDS = {
  p: (e) => e.chain().focus().setParagraph().run(),
  h1: (e) => e.chain().focus().toggleHeading({ level: 1 }).run(),
  h2: (e) => e.chain().focus().toggleHeading({ level: 2 }).run(),
  h3: (e) => e.chain().focus().toggleHeading({ level: 3 }).run(),
}

const LIST_ITEMS = [
  { cmd: 'bulletList', label: 'Bulleted list', text: 'Bulleted list', check: (e) => e.isActive('bulletList') },
  { cmd: 'orderedList', label: 'Numbered list', text: 'Numbered list', check: (e) => e.isActive('orderedList') },
  { cmd: 'taskList', label: 'Task list', text: 'Task list', check: (e) => e.isActive('taskList') },
]

const LIST_CMDS = {
  bulletList: (e) => e.chain().focus().toggleBulletList().run(),
  orderedList: (e) => e.chain().focus().toggleOrderedList().run(),
  taskList: (e) => e.chain().focus().toggleTaskList().run(),
}

const BLOCK_ITEMS = [
  { cmd: 'blockquote', label: 'Blockquote', text: 'Blockquote', check: (e) => e.isActive('blockquote') },
  { cmd: 'codeBlock', label: 'Code block', text: 'Code block', check: (e) => e.isActive('codeBlock') },
]

const BLOCK_CMDS = {
  blockquote: (e) => e.chain().focus().toggleBlockquote().run(),
  codeBlock: (e) => e.chain().focus().toggleCodeBlock().run(),
}

function makeMenu(items, cmds, icon, label, title) {
  const wrap = document.createElement('div')
  wrap.className = 'editor-menu'
  const toggle = makeButton({ icon, label, title })
  toggle.setAttribute('aria-haspopup', 'menu')
  toggle.setAttribute('aria-expanded', 'false')
  const menu = document.createElement('div')
  menu.className = 'editor-menu__dropdown'
  menu.setAttribute('role', 'menu')
  const entries = []
  for (const item of items) {
    const entry = document.createElement('button')
    entry.type = 'button'
    entry.className = 'editor-menu__entry'
    entry.setAttribute('role', 'menuitem')
    entry.textContent = item.text
    entry.setAttribute('aria-pressed', 'false')
    entry.addEventListener('mousedown', (ev) => {
      ev.preventDefault()
      cmds[item.cmd](editorRef.current)
      closeAllMenus()
    })
    entries.push({ item, entry })
    menu.appendChild(entry)
  }
  const closeAllMenus = () => {
    for (const m of document.querySelectorAll('.editor-menu__dropdown')) {
      m.classList.remove('is-open')
    }
    for (const t of document.querySelectorAll('.editor-menu > button')) {
      t.setAttribute('aria-expanded', 'false')
    }
  }
  toggle.addEventListener('mousedown', (ev) => {
    ev.preventDefault()
    const wasOpen = menu.classList.contains('is-open')
    closeAllMenus()
    if (!wasOpen) {
      menu.classList.add('is-open')
      toggle.setAttribute('aria-expanded', 'true')
    }
  })
  document.addEventListener('mousedown', (ev) => {
    if (!wrap.contains(ev.target)) {
      menu.classList.remove('is-open')
      toggle.setAttribute('aria-expanded', 'false')
    }
  })
  wrap.appendChild(toggle)
  wrap.appendChild(menu)
  return { wrap, entries }
}

function createToolbar() {
  const bar = document.createElement('div')
  bar.className = 'editor-toolbar'
  bar.setAttribute('role', 'toolbar')
  bar.setAttribute('aria-label', 'Formatting menubar')

  const buttons = {}

  const add = (key, opts) => {
    const btn = makeButton(opts)
    buttons[key] = btn
    bar.appendChild(btn)
  }
  const addSep = () => {
    const sep = document.createElement('span')
    sep.className = 'editor-toolbar-sep'
    bar.appendChild(sep)
  }

  add('undo', { icon: 'undo', label: 'Undo', title: 'Undo (Ctrl+Z)', keyshortcuts: 'Control+z' })
  add('redo', { icon: 'redo', label: 'Redo', title: 'Redo (Ctrl+Y)', keyshortcuts: 'Control+y' })
  addSep()

  const headingsMenu = makeMenu(HEADING_ITEMS, HEADING_CMDS, 'heading', 'Headings', 'Headings')
  bar.appendChild(headingsMenu.wrap)

  add('bold', { icon: 'bold', label: 'Bold', title: 'Bold (Ctrl+B)', keyshortcuts: 'Control+b', pressed: false })
  add('italic', { icon: 'italic', label: 'Italic', title: 'Italic (Ctrl+I)', keyshortcuts: 'Control+i', pressed: false })
  add('underline', { icon: 'underline', label: 'Underline', title: 'Underline (Ctrl+U)', keyshortcuts: 'Control+u', pressed: false })
  add('strike', { icon: 'strikethrough', label: 'Strikethrough', title: 'Strikethrough', pressed: false })
  add('highlight', { icon: 'highlight', label: 'Highlight', title: 'Highlight', pressed: false })
  addSep()

  const listsMenu = makeMenu(LIST_ITEMS, LIST_CMDS, 'list', 'Lists', 'Lists')
  bar.appendChild(listsMenu.wrap)

  const blocksMenu = makeMenu(BLOCK_ITEMS, BLOCK_CMDS, 'blocks', 'Blocks', 'Blocks')
  bar.appendChild(blocksMenu.wrap)

  add('table', { icon: 'table', label: 'Table', title: 'Insert table', pressed: false })
  add('link', { icon: 'link', label: 'Insert link', title: 'Insert link (Ctrl+K)', keyshortcuts: 'Control+k' })
  addSep()

  const counter = document.createElement('span')
  counter.className = 'editor-counter muted'
  bar.appendChild(counter)

  return { bar, buttons, headingsMenu, listsMenu, blocksMenu, counter }
}

const MARK_COMMANDS = {
  bold: (e) => e.chain().focus().toggleBold().run(),
  italic: (e) => e.chain().focus().toggleItalic().run(),
  underline: (e) => e.chain().focus().toggleUnderline().run(),
  strike: (e) => e.chain().focus().toggleStrike().run(),
  highlight: (e) => e.chain().focus().toggleHighlight().run(),
  table: (e) =>
    e.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(),
  link: (e) => {
    const previousUrl = e.getAttributes('link').href
    const url = window.prompt('Link URL (leave empty to remove)', previousUrl)
    if (url === null) {
      return false
    }
    if (url === '') {
      e.chain().focus().extendMarkRange('link').unsetLink().run()
      return true
    }
    e.chain().focus().extendMarkRange('link').setLink({ href: url }).run()
    return true
  },
}

const MARK_ACTIVE = {
  bold: (e) => e.isActive('bold'),
  italic: (e) => e.isActive('italic'),
  underline: (e) => e.isActive('underline'),
  strike: (e) => e.isActive('strike'),
  highlight: (e) => e.isActive('highlight'),
  table: (e) => e.isActive('table'),
}

function syncToolbar(editor, ui) {
  ui.buttons.undo.disabled = !editor.can().undo()
  ui.buttons.redo.disabled = !editor.can().redo()
  for (const [key, btn] of Object.entries(ui.buttons)) {
    const check = MARK_ACTIVE[key]
    if (check) {
      btn.setAttribute('aria-pressed', check(editor) ? 'true' : 'false')
    }
  }
  for (const { item, entry } of ui.headingsMenu.entries) {
    entry.setAttribute('aria-pressed', item.check(editor) ? 'true' : 'false')
  }
  for (const { item, entry } of ui.listsMenu.entries) {
    entry.setAttribute('aria-pressed', item.check(editor) ? 'true' : 'false')
  }
  for (const { item, entry } of ui.blocksMenu.entries) {
    entry.setAttribute('aria-pressed', item.check(editor) ? 'true' : 'false')
  }
  ui.counter.textContent = editor.storage.characterCount.characters() + ' chars'
}

function enhanceTextarea(textarea) {
  const form = textarea.closest('form')
  if (form === null) {
    return
  }

  const wrap = document.createElement('div')
  wrap.className = 'editor-wrap'

  const ui = createToolbar()
  wrap.appendChild(ui.bar)

  const content = document.createElement('div')
  content.className = 'editor-content'
  wrap.appendChild(content)

  const placeholder =
    textarea.getAttribute('data-editor-placeholder') || 'Start writing...'

  const editor = new Editor({
    element: content,
    contentType: 'markdown',
    content: textarea.value,
    extensions: [
      StarterKit.configure({
        link: { openOnClick: false },
      }),
      Highlight,
      TableKit.configure({ table: { resizable: true } }),
      TaskList,
      TaskItem.configure({ nested: true }),
      Placeholder.configure({ placeholder }),
      CharacterCount,
      Markdown.configure({
        html: false,
        breaks: false,
        linkify: false,
      }),
    ],
    editorProps: {
      attributes: {
        class: 'editor-prose',
        spellcheck: 'true',
      },
    },
  })
  editorRef.current = editor

  for (const [key, btn] of Object.entries(ui.buttons)) {
    const cmd = MARK_COMMANDS[key]
    if (!cmd) {
      continue
    }
    btn.addEventListener('mousedown', (ev) => {
      ev.preventDefault()
      cmd(editor)
    })
  }

  const writeBack = () => {
    textarea.value = editor.getMarkdown()
  }
  editor.on('update', writeBack)
  form.addEventListener('submit', writeBack)

  editor.on('transaction', () => syncToolbar(editor, ui))
  syncToolbar(editor, ui)

  textarea.classList.add('editor-source')
  textarea.hidden = true
  textarea.setAttribute('aria-hidden', 'true')
  textarea.insertAdjacentElement('afterend', wrap)

  const handleKey = (ev) => {
    if ((ev.metaKey || ev.ctrlKey) && ev.key.toLowerCase() === 'k') {
      ev.preventDefault()
      MARK_COMMANDS.link(editor)
    }
    if ((ev.metaKey || ev.ctrlKey) && ev.key.toLowerCase() === 's') {
      ev.preventDefault()
      writeBack()
      form.submit()
    }
  }
  wrap.addEventListener('keydown', handleKey)
  form.addEventListener('keydown', (ev) => {
    if ((ev.metaKey || ev.ctrlKey) && ev.key.toLowerCase() === 's') {
      ev.preventDefault()
      writeBack()
      form.submit()
    }
  })
}

function init() {
  for (const textarea of document.querySelectorAll('textarea[data-editor="tiptap"]')) {
    if (textarea.dataset.editorReady === 'true') {
      continue
    }
    textarea.dataset.editorReady = 'true'
    enhanceTextarea(textarea)
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init)
} else {
  init()
}

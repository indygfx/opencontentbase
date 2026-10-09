import { Editor } from '@tiptap/core'
import { StarterKit } from '@tiptap/starter-kit'
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

const TOOLBAR_BUTTONS = [
  { cmd: 'undo', label: 'Undo', title: 'Undo (Ctrl+Z)' },
  { cmd: 'redo', label: 'Redo', title: 'Redo (Ctrl+Y)' },
  { sep: true },
  { cmd: 'p', label: 'Paragraph', title: 'Paragraph' },
  { cmd: 'h1', label: 'Heading 1', title: 'Heading 1' },
  { cmd: 'h2', label: 'Heading 2', title: 'Heading 2' },
  { cmd: 'h3', label: 'Heading 3', title: 'Heading 3' },
  { sep: true },
  { cmd: 'bold', label: 'Bold', title: 'Bold (Ctrl+B)' },
  { cmd: 'italic', label: 'Italic', title: 'Italic (Ctrl+I)' },
  { cmd: 'strike', label: 'Strikethrough', title: 'Strikethrough' },
  { cmd: 'underline', label: 'Underline', title: 'Underline (Ctrl+U)' },
  { cmd: 'code', label: 'Code', title: 'Inline code' },
  { sep: true },
  { cmd: 'bulletList', label: 'Bullet list', title: 'Bullet list' },
  { cmd: 'orderedList', label: 'Ordered list', title: 'Ordered list' },
  { cmd: 'taskList', label: 'Task list', title: 'Task list' },
  { cmd: 'blockquote', label: 'Quote', title: 'Blockquote' },
  { cmd: 'codeBlock', label: 'Code block', title: 'Code block' },
  { sep: true },
  { cmd: 'table', label: 'Table', title: 'Insert table' },
  { cmd: 'link', label: 'Link', title: 'Link (Ctrl+K)' },
]

const ACTIVE_CHECKS = {
  p: (editor) => editor.isActive('paragraph'),
  h1: (editor) => editor.isActive('heading', { level: 1 }),
  h2: (editor) => editor.isActive('heading', { level: 2 }),
  h3: (editor) => editor.isActive('heading', { level: 3 }),
  bold: (editor) => editor.isActive('bold'),
  italic: (editor) => editor.isActive('italic'),
  strike: (editor) => editor.isActive('strike'),
  underline: (editor) => editor.isActive('underline'),
  code: (editor) => editor.isActive('code'),
  bulletList: (editor) => editor.isActive('bulletList'),
  orderedList: (editor) => editor.isActive('orderedList'),
  taskList: (editor) => editor.isActive('taskList'),
  blockquote: (editor) => editor.isActive('blockquote'),
  codeBlock: (editor) => editor.isActive('codeBlock'),
  link: (editor) => editor.isActive('link'),
}

const TOGGLE_COMMANDS = {
  undo: (editor) => editor.chain().focus().undo().run(),
  redo: (editor) => editor.chain().focus().redo().run(),
  p: (editor) => editor.chain().focus().setParagraph().run(),
  h1: (editor) => editor.chain().focus().toggleHeading({ level: 1 }).run(),
  h2: (editor) => editor.chain().focus().toggleHeading({ level: 2 }).run(),
  h3: (editor) => editor.chain().focus().toggleHeading({ level: 3 }).run(),
  bold: (editor) => editor.chain().focus().toggleBold().run(),
  italic: (editor) => editor.chain().focus().toggleItalic().run(),
  strike: (editor) => editor.chain().focus().toggleStrike().run(),
  underline: (editor) => editor.chain().focus().toggleUnderline().run(),
  code: (editor) => editor.chain().focus().toggleCode().run(),
  bulletList: (editor) => editor.chain().focus().toggleBulletList().run(),
  orderedList: (editor) => editor.chain().focus().toggleOrderedList().run(),
  taskList: (editor) => editor.chain().focus().toggleTaskList().run(),
  blockquote: (editor) => editor.chain().focus().toggleBlockquote().run(),
  codeBlock: (editor) => editor.chain().focus().toggleCodeBlock().run(),
  table: (editor) =>
    editor
      .chain()
      .focus()
      .insertTable({ rows: 3, cols: 3, withHeaderRow: true })
      .run(),
  link: (editor) => {
    const previousUrl = editor.getAttributes('link').href
    const url = window.prompt('Link URL (leave empty to remove)', previousUrl)
    if (url === null) {
      return false
    }
    if (url === '') {
      editor.chain().focus().extendMarkRange('link').unsetLink().run()
      return true
    }
    editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run()
    return true
  },
}

function createToolbar(editor) {
  const bar = document.createElement('div')
  bar.className = 'editor-toolbar'
  const buttons = {}
  for (const item of TOOLBAR_BUTTONS) {
    if (item.sep) {
      const sep = document.createElement('span')
      sep.className = 'editor-toolbar-sep'
      bar.appendChild(sep)
      continue
    }
    const btn = document.createElement('button')
    btn.type = 'button'
    btn.className = 'editor-toolbar-btn'
    btn.textContent = item.label
    btn.title = item.title
    btn.setAttribute('aria-pressed', 'false')
    btn.addEventListener('mousedown', (ev) => {
      ev.preventDefault()
      TOGGLE_COMMANDS[item.cmd](editor)
    })
    buttons[item.cmd] = btn
    bar.appendChild(btn)
  }
  return { bar, buttons }
}

function syncToolbar(editor, buttons) {
  for (const [cmd, btn] of Object.entries(buttons)) {
    const check = ACTIVE_CHECKS[cmd]
    if (!check) {
      if (cmd === 'undo') {
        btn.disabled = !editor.can().undo()
      } else if (cmd === 'redo') {
        btn.disabled = !editor.can().redo()
      }
      continue
    }
    btn.setAttribute('aria-pressed', check(editor) ? 'true' : 'false')
  }
}

function enhanceTextarea(textarea) {
  const form = textarea.closest('form')
  if (form === null) {
    return
  }

  const wrap = document.createElement('div')
  wrap.className = 'editor-wrap'

  const { bar, buttons } = createToolbar(null)
  const counter = document.createElement('span')
  counter.className = 'editor-counter muted'
  bar.appendChild(counter)
  wrap.appendChild(bar)

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

  // Toolbar needs the editor; rebuild commands with a late-bound reference
  for (const item of TOOLBAR_BUTTONS) {
    if (item.sep) {
      continue
    }
    const btn = buttons[item.cmd]
    btn.addEventListener('mousedown', (ev) => {
      ev.preventDefault()
      TOGGLE_COMMANDS[item.cmd](editor)
    })
  }

  const writeBack = () => {
    textarea.value = editor.getMarkdown()
  }
  editor.on('update', writeBack)
  form.addEventListener('submit', writeBack)

  editor.on('transaction', () => {
    syncToolbar(editor, buttons)
    counter.textContent =
      editor.storage.characterCount.characters() + ' chars'
  })
  syncToolbar(editor, buttons)

  textarea.classList.add('editor-source')
  textarea.hidden = true
  textarea.setAttribute('aria-hidden', 'true')
  textarea.insertAdjacentElement('afterend', wrap)

  const handleKey = (ev) => {
    if ((ev.metaKey || ev.ctrlKey) && ev.key.toLowerCase() === 'k') {
      ev.preventDefault()
      TOGGLE_COMMANDS.link(editor)
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

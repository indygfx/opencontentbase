<section class="card">
    <p class="muted"><a href="/writing">&larr; All projects</a></p>
    <h1><?= e($project['title']) ?></h1>
    <?php if (!empty($error)): ?>
        <p class="error"><?= e($error) ?></p>
    <?php endif; ?>

    <h2>Schneeflocke</h2>
    <dl>
        <dt>Kurzbeschreibung <span class="muted">(Schritt 2 – ein Absatz: Setup, 3 Wendepunkte, Ende)</span></dt>
        <dd><?= $project['blurb'] !== '' ? nl2br(e($project['blurb'])) : '<span class="muted">Noch nicht erfasst.</span>' ?></dd>
        <dt>Synopsis <span class="muted">(Schritte 4/6 – Kapitel&uuml;bersicht)</span></dt>
        <dd><?= $project['synopsis'] !== '' ? nl2br(e($project['synopsis'])) : '<span class="muted">Noch nicht erfasst.</span>' ?></dd>
        <dt>Erweiterte Synopsis</dt>
        <dd><?= $project['synopsis_long'] !== '' ? nl2br(e($project['synopsis_long'])) : '<span class="muted">Noch nicht erfasst.</span>' ?></dd>
    </dl>

    <h2>Chapters</h2>
    <?php if ($chapters !== []): ?>
        <table>
            <tr><th>Title</th><th>Idee</th><th>Slug</th><th></th></tr>
            <?php foreach ($chapters as $ch): ?>
                <tr>
                    <td><a href="/writing/<?= e($project['id']) ?>/chapters/<?= e($ch['slug']) ?>"><?= e($ch['title']) ?></a></td>
                    <td class="muted"><?= e(mb_strimwidth((string)($ch['summary'] ?? ''), 0, 60, '…')) ?></td>
                    <td class="muted"><?= e($ch['slug']) ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/chapters/<?= e($ch['slug']) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="muted">No chapters yet.</p>
    <?php endif; ?>
    <p><a class="button" href="/writing/<?= e($project['id']) ?>/chapters/new">New chapter</a></p>

    <h2>Characters</h2>
    <?php if ($characters !== []): ?>
        <table>
            <tr><th>Name</th><th>Slug</th><th></th></tr>
            <?php foreach ($characters as $ch): ?>
                <tr>
                    <td><a href="/writing/<?= e($project['id']) ?>/characters/<?= e($ch['slug']) ?>"><?= e($ch['title']) ?></a></td>
                    <td class="muted"><?= e($ch['slug']) ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/characters/<?= e($ch['slug']) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="muted">No characters yet.</p>
    <?php endif; ?>
    <p><a class="button" href="/writing/<?= e($project['id']) ?>/characters/new">New character</a></p>

    <h2>Backgrounds</h2>
    <?php if ($backgrounds !== []): ?>
        <table>
            <tr><th>Title</th><th>Slug</th><th></th></tr>
            <?php foreach ($backgrounds as $b): ?>
                <tr>
                    <td><a href="/writing/<?= e($project['id']) ?>/backgrounds/<?= e($b['slug']) ?>"><?= e($b['title']) ?></a></td>
                    <td class="muted"><?= e($b['slug']) ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/backgrounds/<?= e($b['slug']) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="muted">No backgrounds yet.</p>
    <?php endif; ?>
    <p><a class="button" href="/writing/<?= e($project['id']) ?>/backgrounds/new">New background</a></p>

    <h2>Relationships</h2>
    <?php if ($relations !== []): ?>
        <table>
            <tr><th>From</th><th>Kind</th><th>To</th><th>Description</th><th></th></tr>
            <?php foreach ($relations as $r): ?>
                <tr>
                    <td><a href="/writing/<?= e($project['id']) ?>/characters/<?= e($r['from_slug'] ?? '') ?>"><?= e($r['from_title']) ?></a></td>
                    <td><?= e($r['kind']) ?></td>
                    <td><a href="/writing/<?= e($project['id']) ?>/characters/<?= e($r['to_slug'] ?? '') ?>"><?= e($r['to_title']) ?></a></td>
                    <td class="muted"><?= e($r['description']) ?></td>
                    <td>
                        <form class="inline" method="post" action="/writing/<?= e($project['id']) ?>/relations/delete">
                            <?= $csrf->field() ?>
                            <input type="hidden" name="relation_id" value="<?= e($r['id']) ?>">
                            <button type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p class="muted">No relationships yet.</p>
    <?php endif; ?>
    <?php if (count($characterOptions) >= 2): ?>
        <h3>New relationship</h3>
        <form method="post" action="/writing/<?= e($project['id']) ?>/relations">
            <?= $csrf->field() ?>
            <label for="from_character_id">From</label>
            <select id="from_character_id" name="from_character_id" required>
                <?php foreach ($characterOptions as $c): ?>
                    <option value="<?= e($c['id']) ?>"><?= e($c['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="kind">Kind</label>
            <select id="kind" name="kind">
                <?php foreach (['related', 'family', 'friend', 'rival', 'lover', 'mentor', 'ally', 'enemy'] as $k): ?>
                    <option value="<?= e($k) ?>"><?= e($k) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="to_character_id">To</label>
            <select id="to_character_id" name="to_character_id" required>
                <?php foreach ($characterOptions as $c): ?>
                    <option value="<?= e($c['id']) ?>"><?= e($c['title']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="description">Description <small class="muted">(optional)</small></label>
            <input id="description" name="description" maxlength="500" placeholder="how they relate">
            <button type="submit">Add</button>
        </form>
    <?php else: ?>
        <p class="muted">Create at least two characters to define relationships.</p>
    <?php endif; ?>

    <?php if ($isOwner): ?>
        <h2>Edit project (Schneeflocke)</h2>
        <form method="post" action="/writing/<?= e($project['id']) ?>">
            <?= $csrf->field() ?>
            <label for="title">Title</label>
            <input id="title" name="title" required minlength="2" maxlength="200" value="<?= e($project['title']) ?>">
            <label for="blurb">Kurzbeschreibung <small class="muted">(Schneeflocke Schritt 2)</small></label>
            <textarea id="blurb" name="blurb" rows="4" placeholder="ein Absatz: Setup, 3 Wendepunkte, Ende"><?= e($project['blurb']) ?></textarea>
            <label for="synopsis">Synopsis <small class="muted">(Schritte 4/6)</small></label>
            <textarea id="synopsis" name="synopsis" rows="6" placeholder="Kapitel&uuml;bersicht"><?= e($project['synopsis']) ?></textarea>
            <label for="synopsis_long">Erweiterte Synopsis</label>
            <textarea id="synopsis_long" name="synopsis_long" rows="6"><?= e($project['synopsis_long']) ?></textarea>
            <button type="submit">Save</button>
        </form>

        <h2>Share project</h2>
        <?php if ($shareTargets !== []): ?>
            <form method="post" action="/writing/<?= e($project['id']) ?>/share">
                <?= $csrf->field() ?>
                <label for="username">Username</label>
                <select id="username" name="username" required>
                    <option value="" disabled selected>who to invite</option>
                    <?php foreach ($shareTargets as $t): ?>
                        <option value="<?= e($t['username']) ?>"><?= e($t['username']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Share</button>
            </form>
        <?php else: ?>
            <p class="muted">There is no other user left to share this project with.</p>
        <?php endif; ?>

        <?php if ($members !== []): ?>
            <h3>Shared with</h3>
            <table>
                <tr><th>User</th><th>Role</th><th></th></tr>
                <?php foreach ($members as $m): ?>
                    <tr>
                        <td><?= e($m['username']) ?></td>
                        <td><?= e($m['role']) ?></td>
                        <td>
                            <form class="inline" method="post" action="/writing/<?= e($project['id']) ?>/unshare">
                                <?= $csrf->field() ?>
                                <input type="hidden" name="user_id" value="<?= e($m['user_id']) ?>">
                                <button type="submit">Revoke</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

        <h2>Danger zone</h2>
        <form method="post" action="/writing/<?= e($project['id']) ?>/delete">
            <?= $csrf->field() ?>
            <button type="submit">Delete project</button>
        </form>
    <?php else: ?>
        <p class="muted">Shared with you by <?= e($project['owner_name'] ?? '') ?>. You can read and edit texts, but not manage the project.</p>
    <?php endif; ?>
</section>

<?php

// Deutsch – Kern-UI. Fehlende Schlüssel fallen automatisch auf Englisch
// (lang/en/) zurück. Neue Sprache: diesen Ordner kopieren und übersetzen.

return [
    // Layout / Navigation
    'brand' => 'ContentBase',
    'nav_pages' => 'Seiten',
    'nav_new_page' => 'Neue Seite',
    'nav_users' => 'Benutzer',
    'nav_writing' => 'Schreiben',
    'nav_sign_in' => 'Anmelden',
    'nav_sign_out' => 'Abmelden',

    // Login
    'login_title' => 'Anmelden',
    'login_username' => 'Benutzername',
    'login_password' => 'Passwort',
    'login_submit' => 'Anmelden',
    'login_error' => 'Falscher Benutzername oder falsches Passwort.',

    // Profil
    'profile_title' => 'Profil',
    'profile_username' => 'Benutzername',
    'profile_role' => 'Rolle',
    'profile_language' => 'Sprache',
    'profile_change_password' => 'Passwort ändern',
    'profile_current_password' => 'Aktuelles Passwort',
    'profile_new_password' => 'Neues Passwort',
    'profile_confirm_password' => 'Neues Passwort bestätigen',
    'profile_change_password_submit' => 'Passwort ändern',
    'profile_password_changed' => 'Passwort geändert. Andere Sitzungen wurden abgemeldet.',
    'profile_language_invalid' => 'Die gewählte Sprache ist nicht verfügbar.',
    'profile_language_submit' => 'Sprache speichern',
    'profile_language_changed' => 'Sprache aktualisiert.',

    // Benutzerverwaltung
    'users_title' => 'Benutzerverwaltung',
    'users_create' => 'Benutzer anlegen',
    'users_existing' => 'Bestehende Benutzer',
    'users_role' => 'Rolle',
    'users_active_sessions' => 'Aktive Sitzungen',
    'users_actions' => 'Aktionen',
    'users_create_submit' => 'Anlegen',
    'users_set_role' => 'Rolle setzen',
    'users_reset_password' => 'Passwort zurücksetzen',
    'users_reset' => 'Zurücksetzen',
    'users_delete' => 'Löschen',
    'users_fixed' => '(fixiert)',
    'users_not_found' => 'Benutzer nicht gefunden.',
    'users_error_username_short' => 'Der Benutzername muss mindestens 3 Zeichen lang sein.',
    'users_error_username_chars' => 'Der Benutzername darf nur Buchstaben, Zahlen, Unterstrich, Punkt und Bindestrich enthalten.',
    'users_error_min_length' => 'Benutzername und Passwort sind zu kurz (min. 3 und 8 Zeichen).',
    'users_error_duplicate' => 'Dieser Benutzername existiert bereits.',
    'users_error_role' => 'Ungültige Rolle.',
    'users_error_password' => 'Passwort zu kurz (min. 8 Zeichen).',
    'users_error_confirm' => 'Die Passwörter stimmen nicht überein.',
    'users_created' => 'Benutzer angelegt.',
    'users_role_updated' => 'Rolle aktualisiert.',
    'users_password_reset' => 'Passwort zurückgesetzt.',
    'users_deleted' => 'Benutzer gelöscht.',
    'users_error_delete_self' => 'Du kannst dein eigenes Konto nicht löschen.',
    'users_error_admin_fixed' => 'Das ursprüngliche Admin-Konto kann nicht geändert oder gelöscht werden.',

    // Fehler
    'error_back_to_overview' => 'Zurück zur Übersicht',

    // Sprachnamen (für das Sprach-Dropdown)
    'lang_en' => 'English',
    'lang_de' => 'Deutsch',
];

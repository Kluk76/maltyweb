# VPS Infrastructure — Configuration manuelle

> Configurations appliquées directement sur le VPS (`ubuntu@83.228.215.243`) qui ne sont **pas** gérées par git. À refaire si le VPS est reconstruit.

## Postfix — sender-dependent relay (Google Workspace)

**Objectif :** les mails envoyés depuis `@lanebuleuse.ch` transitent par le relais SMTP Google Workspace ; les mails `@maltytask.ch` restent sur IONOS.

### `/etc/postfix/main.cf` — ligne ajoutée

```
sender_dependent_relayhost_maps = hash:/etc/postfix/sender_relay
```

### `/etc/postfix/sender_relay` — fichier créé

```
@lanebuleuse.ch    [smtp-relay.gmail.com]:587
```

Auth : IP-based (pas de SASL). L'IP du VPS (`83.228.215.243`) est autorisée dans la Google Admin Console par Arthur (La Nébuleuse).

### Commandes appliquées

```bash
sudo postmap /etc/postfix/sender_relay
sudo postfix reload
```

### Vérification

```bash
sudo tail -f /var/log/mail.log
# relay=smtp-relay.gmail.com[142.251.127.28]:587, dsn=2.0.0, status=sent
```

### Déclencheur initial

Nécessaire pour la feature email de confirmation de commande (commit `04ca286`, 2026-06-19) : `commandes@lanebuleuse.ch` → client via Google relay, `noreply@maltytask.ch` → IONOS (flux existants inchangés).

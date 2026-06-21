"""
comm_domains.py — Shared consumer/personal-domain constants for the comm
                  entity registry pipeline.

Import pattern (other scripts):
  from comm_domains import CONSUMER_DOMAINS, domain_of

CONSUMER_DOMAINS is a frozenset of lowercase bare domains that are
shared-inbox / personal-mailbox providers.  Emails on these domains
CANNOT be used to derive a domain→supplier mapping (many senders share the
same domain across many unrelated entities).  Address-level pins
(comm_address_pins) are the correct routing mechanism for these addresses.

This module is the SINGLE authoritative source of this set — do NOT
inline-copy it anywhere else.
"""

from __future__ import annotations

CONSUMER_DOMAINS: frozenset[str] = frozenset({
    "gmail.com",
    "googlemail.com",
    "outlook.com",
    "hotmail.com",
    "hotmail.fr",
    "hotmail.ch",
    "live.com",
    "live.fr",
    "msn.com",
    "yahoo.com",
    "yahoo.fr",
    "ymail.com",
    "bluewin.ch",
    "gmx.ch",
    "gmx.net",
    "gmx.de",
    "icloud.com",
    "me.com",
    "mac.com",
    "proton.me",
    "protonmail.com",
    "sunrise.ch",
    "hispeed.ch",
    "swissonline.ch",
    "vtxnet.ch",
    "green.ch",
})


def domain_of(email: str) -> str:
    """
    Extract the bare lowercase domain from an email address.
    'User@Example.COM'  →  'example.com'
    'no-at-sign'        →  ''
    """
    email = email.strip().lower()
    if "@" not in email:
        return ""
    return email.rsplit("@", 1)[1].strip()

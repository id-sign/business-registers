# Security policy

## Supported versions

Security fixes go into the latest release only. Before `v1.0.0`, a fix may come with a minor release that also breaks
the API; `UPGRADE.md` lists what to change.

## Reporting a vulnerability

Report it privately through GitHub: **Security › Report a vulnerability** in
[id-sign/business-registers](https://github.com/id-sign/business-registers/security/advisories/new). Do not open a
public issue.

Include the affected version, a description and, if you can, a reproduction with recorded responses instead of real
personal data.

Examples of what counts: register response data reaching an exception message, XML parsing that resolves
external entities or reaches the network, a crafted response that makes a client hang or exhaust memory.

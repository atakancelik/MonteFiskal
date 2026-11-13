# Never in the tree

- Certificates, private keys, `.p12`/`.pfx`/`.pem`/`.key` files, passwords, tokens.
- A real taxpayer number (PIB/JMB), real business unit, TCR, software, maintainer or operator codes,
  real invoices, IKOF or JIKR values from production.
- Test certificates from other open-source repositories.

Tests create throw-away keys at run time (`TestCertificateFactory`). Examples use placeholders such as
`<your business unit code>` or the fake-service values (`12345678`, `bb123bb123`, `cc123cc123`).

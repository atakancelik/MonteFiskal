using System.Diagnostics;
using System.Diagnostics.Metrics;
using System.Globalization;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Text.Json;
using System.Text.Json.Serialization;
using MonteFiskal.Calculation;
using MonteFiskal.Errors;
using MonteFiskal.Model;
using MonteFiskal.Numbering;
using MonteFiskal.Outbox;
using MonteFiskal.Security;
using MonteFiskal.Text;
using MonteFiskal.Transport;
using MonteFiskal.Validation;
using MonteFiskal.Verification;
using MonteFiskal.Wire;

namespace MonteFiskal;

/// <summary>An issued invoice: everything needed to print it, with or without JIKR (BR-J2, BR-P1).</summary>
public sealed record IssuedInvoice(
    Guid Id,
    string Number,
    long Ordinal,
    string Iic,
    string IicSignature,
    string VerificationUrl,
    string IssueDateTime,
    InvoiceState State,
    CalculatedTotals Totals,
    TypeOfInvoice TypeOfInv,
    IReadOnlyList<Finding> Warnings);

/// <summary>Result of one send attempt. Never an exception for a fiscal fault.</summary>
public sealed record SendResult(InvoiceState State, string? Fic, FiscalError? Error, string? Problem = null);

public sealed record FiscalizedInvoice(IssuedInvoice Invoice, string Fic, DateTimeOffset RegisteredAt);

public sealed record TcrRegistrationResult(bool Success, string? TcrCode, FiscalError? Error, string? Problem = null);

public sealed record CashDepositResult(Guid Id, InvoiceState State, string? Fcdc, FiscalError? Error, string? Problem = null);

public sealed record ResendOptions(int MaxItems = 50, TimeSpan? Budget = null);

public sealed record ResendSummary(int Attempted, int Fiscalized, int StillPending, int Rejected);

/// <summary>Full reversal of a stored invoice (BR-C2) or a partial correction with explicit lines (BR-C5).</summary>
public sealed record CorrectionRequest(Guid OriginalId, CorrectionType Type = CorrectionType.Corrective, IReadOnlyList<InvoiceLine>? Lines = null, IReadOnlyList<Payment>? Payments = null);

/// <summary>Entry point (architecture §2.3).</summary>
public interface IFiscalClient
{
    Task<IssuedInvoice> IssueInvoiceAsync(InvoiceDraft draft, CancellationToken cancellationToken = default);
    Task<SendResult> SendAsync(Guid invoiceId, CancellationToken cancellationToken = default);
    Task<FiscalizedInvoice?> IssueAndSendAsync(InvoiceDraft draft, CancellationToken cancellationToken = default);
    Task<IssuedInvoice> IssueCorrectiveAsync(CorrectionRequest request, CancellationToken cancellationToken = default);
    Task<TcrRegistrationResult> RegisterTcrAsync(TcrRegistration registration, CancellationToken cancellationToken = default);
    Task<TcrRegistrationResult> DeregisterTcrAsync(string tcrInternalId, DateOnly validTo, CancellationToken cancellationToken = default);
    Task<CashDepositResult> RegisterCashDepositAsync(CashDeposit deposit, CancellationToken cancellationToken = default);
    Task<ResendSummary> ResendPendingAsync(ResendOptions options, CancellationToken cancellationToken = default);
    IkofCheck RecomputeIkof(IkofParameters parameters);
    string BuildVerificationUrl(IkofParameters parameters, string iic);
}

/// <summary>JSON settings shared by the library and its tests (camel case, decimals from strings, wire enum names).</summary>
public static class MonteFiskalJson
{
    public static JsonSerializerOptions Options { get; } = new(JsonSerializerDefaults.Web)
    {
        NumberHandling = JsonNumberHandling.AllowReadingFromString,
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
    };
}

/// <summary>Telemetry names (architecture §2.1).</summary>
public static class MonteFiskalTelemetry
{
    public const string Name = "MonteFiskal";
    public static readonly ActivitySource ActivitySource = new(Name);
    public static readonly Meter Meter = new(Name);
    internal static readonly Counter<long> Issued = Meter.CreateCounter<long>("montefiskal.invoices.issued");
    internal static readonly Counter<long> Sent = Meter.CreateCounter<long>("montefiskal.requests.sent");
    internal static readonly Counter<long> Faults = Meter.CreateCounter<long>("montefiskal.requests.faults");
}

/// <summary>Generates header UUIDs (version 4, as the schema requires).</summary>
public interface IUuidGenerator
{
    Guid NewUuid();
}

public sealed class RandomUuidGenerator : IUuidGenerator
{
    public static RandomUuidGenerator Instance { get; } = new();
    public Guid NewUuid() => Guid.NewGuid();
}

/// <summary>Default implementation of <see cref="IFiscalClient"/>.</summary>
public sealed class FiscalClient : IFiscalClient
{
    private readonly FiscalProfile _profile;
    private readonly ICertificateProvider _certificates;
    private readonly IFiscalTransport _transport;
    private readonly IOutboxStore _store;
    private readonly MonteFiskalOptions _options;
    private readonly TimeProvider _clock;
    private readonly IUuidGenerator _uuids;
    private readonly IRoundingPolicy _rounding;
    private readonly Archive.IArchiveStore _archive;

    public FiscalClient(
        FiscalProfile profile,
        ICertificateProvider certificates,
        IFiscalTransport transport,
        IOutboxStore store,
        MonteFiskalOptions? options = null,
        TimeProvider? clock = null,
        IUuidGenerator? uuids = null,
        IRoundingPolicy? rounding = null,
        Archive.IArchiveStore? archive = null)
    {
        _profile = profile ?? throw new ArgumentNullException(nameof(profile));
        _certificates = certificates ?? throw new ArgumentNullException(nameof(certificates));
        _transport = transport ?? throw new ArgumentNullException(nameof(transport));
        _store = store ?? throw new ArgumentNullException(nameof(store));
        _options = options ?? new MonteFiskalOptions();
        _clock = clock ?? TimeProvider.System;
        _uuids = uuids ?? RandomUuidGenerator.Instance;
        _rounding = rounding ?? InvoiceCalculator.Default;
        _archive = archive ?? Archive.NullArchiveStore.Instance;
    }

    public FiscalProfile Profile => _profile;

    private DateTimeOffset Now => _clock.GetUtcNow();

    /// <inheritdoc />
    public async Task<IssuedInvoice> IssueInvoiceAsync(InvoiceDraft draft, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(draft);
        using var activity = MonteFiskalTelemetry.ActivitySource.StartActivity("IssueInvoice");
        var issuedAt = draft.IssuedAt ?? Now;
        draft = Prepare(draft with { IssuedAt = issuedAt });
        var result = InvoiceValidator.Validate(_profile, draft, Now, _options, _rounding);
        if (!result.IsValid)
        {
            throw new FiscalValidationException(result);
        }
        var cert = _certificates.GetCertificate();
        using var key = cert.GetRSAPrivateKey() ?? throw new FiscalConfigurationException("The certificate has no RSA private key.");
        var sequenceKey = SequenceKey(issuedAt);
        var stored = await _store.IssueAsync(sequenceKey, ordinal => Build(draft, ordinal, key), cancellationToken).ConfigureAwait(false);
        MonteFiskalTelemetry.Issued.Add(1);
        return ToIssued(stored, result.Warnings.ToList());
    }

    private StoredRequest Build(InvoiceDraft draft, long ordinal, RSA key)
    {
        var facts = RequestBuilder.Facts(_profile, draft, ordinal, _options, _rounding);
        // BR-I4: the IKOF is computed from the same formatted strings that are sent.
        var ikof = Ikof.Compute(facts.IicConcat, key);
        var invoice = RequestBuilder.Invoice(_profile, draft, facts, new IssueValues(ordinal, ikof.Iic, ikof.IicSignature), _options).ToString();
        var probe = RequestBuilder.InvoiceRequest(invoice, new RequestHeader(_uuids.NewUuid(), Now), _profile.TimeZone, _options);
        var schemaErrors = SchemaSet.ValidateUnsigned(probe);
        if (schemaErrors.Count > 0)
        {
            // BR-O10: never store a request the schema rejects; the ordinal is not consumed because Build throws.
            throw new FiscalValidationException(new ValidationResult(schemaErrors.Select(e => new Finding("SCHEMA", Severity.Error, "RegisterInvoiceRequest", e)).ToList()));
        }
        var parameters = new IkofParameters(_profile.IssuerTin, facts.IssueDateTime, ordinal.ToString(CultureInfo.InvariantCulture), _profile.BusinessUnit, _profile.Tcr, _profile.Software, facts.Totals.TotPrice);
        return new StoredRequest
        {
            Id = _uuids.NewUuid(),
            Operation = FiscalOperation.RegisterInvoice,
            Body = invoice,
            IssuerTin = _profile.IssuerTin,
            BusinessUnit = _profile.BusinessUnit,
            Tcr = _profile.Tcr,
            InvoiceNumber = facts.InvNum,
            Iic = ikof.Iic,
            IicSignature = ikof.IicSignature,
            IssueDateTime = facts.IssueDateTime,
            IssuedAt = draft.IssuedAt!.Value,
            Kind = draft.Kind,
            TypeOfInv = facts.TypeOfInv,
            Total = facts.Totals.TotPrice,
            VerificationUrl = BuildVerificationUrl(parameters, ikof.Iic),
            DependsOnIic = draft.Correction?.IicRef,
            DraftJson = JsonSerializer.Serialize(draft, MonteFiskalJson.Options),
        };
    }

    private InvoiceDraft Prepare(InvoiceDraft d)
    {
        if (_options.Text != TextPolicy.Transliterate)
        {
            return d;
        }
        // Q-07: optional transliteration; never applied by default (BR-T8).
        static string? T(string? s) => s is null ? null : TextRules.Transliterate(s);
        return d with
        {
            Buyer = d.Buyer is null ? null : d.Buyer with { Name = T(d.Buyer.Name)!, Address = T(d.Buyer.Address), Town = T(d.Buyer.Town) },
            Lines = d.Lines.Select(l => l with { Name = T(l.Name)!, Unit = T(l.Unit)!, Code = T(l.Code) }).ToList(),
            Note = T(d.Note),
        };
    }

    private string SequenceKey(DateTimeOffset issuedAt)
    {
        // BR-N3: one sequence per TCR (default) or per business unit, and per calendar year in local time (BR-N4).
        var year = FiscalTime.LocalYear(issuedAt, _profile.TimeZone).ToString(CultureInfo.InvariantCulture);
        return _options.SequenceScope == SequenceScope.BusinessUnitYear
            ? $"{_profile.IssuerTin}/{_profile.BusinessUnit}/{year}"
            : $"{_profile.IssuerTin}/{_profile.BusinessUnit}/{_profile.Tcr}/{year}";
    }

    private static IssuedInvoice ToIssued(StoredRequest s, IReadOnlyList<Finding> warnings)
    {
        var draft = JsonSerializer.Deserialize<InvoiceDraft>(s.DraftJson!, MonteFiskalJson.Options)!;
        _ = draft;
        var totals = TotalsFromBody(s.Body);
        return new IssuedInvoice(s.Id, s.InvoiceNumber!, s.Ordinal ?? 0, s.Iic!, s.IicSignature!, s.VerificationUrl!, s.IssueDateTime!, s.State, totals, s.TypeOfInv ?? TypeOfInvoice.Cash, warnings);
    }

    private static CalculatedTotals TotalsFromBody(string body)
    {
        static string? A(string body, string name)
        {
            var i = body.IndexOf(" " + name + "=\"", StringComparison.Ordinal);
            if (i < 0)
            {
                return null;
            }
            i += name.Length + 3;
            return body[i..body.IndexOf('"', i)];
        }
        var head = body[..body.IndexOf('>', StringComparison.Ordinal)];
        return new CalculatedTotals(A(head, "TotPriceWoVAT") ?? "0.00", A(head, "TotVATAmt"), A(head, "TotPrice") ?? "0.00");
    }

    /// <inheritdoc />
    public async Task<SendResult> SendAsync(Guid invoiceId, CancellationToken cancellationToken = default)
    {
        var stored = await _store.GetAsync(invoiceId, cancellationToken).ConfigureAwait(false) ?? throw new ArgumentException("Unknown request " + invoiceId, nameof(invoiceId));
        return await SendStoredAsync(stored, cancellationToken).ConfigureAwait(false);
    }

    private async Task<SendResult> SendStoredAsync(StoredRequest stored, CancellationToken ct)
    {
        if (stored.State is InvoiceState.Fiscalized)
        {
            return new SendResult(stored.State, stored.Result, null);
        }
        if (stored.State is InvoiceState.Rejected)
        {
            return new SendResult(stored.State, null, stored.LastError, "rejected; needs a person");
        }
        if (stored.Operation == FiscalOperation.RegisterInvoice)
        {
            // BR-C3: the original must be registered before its corrective invoice is sent.
            if (stored.DependsOnIic is { } original)
            {
                var orig = await _store.FindByIicAsync(original, ct).ConfigureAwait(false);
                if (orig is not null && orig.State != InvoiceState.Fiscalized)
                {
                    return new SendResult(stored.State, null, null, "waiting for the original invoice to be fiscalized (BR-C3)");
                }
            }
            // BR-D5: no cash invoice before the day's INITIAL deposit.
            if (stored.TypeOfInv == TypeOfInvoice.Cash && _options.AutoInitialDeposit)
            {
                var ok = await EnsureInitialDepositAsync(stored.IssuedAt, ct).ConfigureAwait(false);
                if (!ok)
                {
                    // The invoice could not be delivered at issue; when it goes out later it is a subsequent delivery (BR-O7).
                    var waiting = stored with { State = Pending(stored), SubsequentDelivery = stored.SubsequentDelivery ?? SubsequentDelivery.NoInternet };
                    await _store.UpdateAsync(waiting, ct).ConfigureAwait(false);
                    return new SendResult(waiting.State, null, null, "waiting for the day's INITIAL cash deposit (BR-D5)");
                }
            }
        }

        var cert = _certificates.GetCertificate();
        using var key = cert.GetRSAPrivateKey() ?? throw new FiscalConfigurationException("The certificate has no RSA private key.");
        var header = new RequestHeader(_uuids.NewUuid(), Now, stored.Attempts.Count > 0 || stored.SubsequentDelivery is not null ? stored.SubsequentDelivery ?? SubsequentDelivery.NoInternet : null);
        var unsigned = stored.Operation switch
        {
            FiscalOperation.RegisterInvoice => RequestBuilder.InvoiceRequest(stored.Body, header, _profile.TimeZone, _options),
            FiscalOperation.RegisterTcr => RequestBuilder.Root("RegisterTCRRequest", RequestBuilder.Header(header with { SubsequentDelivery = null }, _profile.TimeZone, _options), XNode.FromRaw(stored.Body)).ToString(),
            _ => RequestBuilder.Root("RegisterCashDepositRequest", RequestBuilder.Header(header, _profile.TimeZone, _options), XNode.FromRaw(stored.Body)).ToString(),
        };
        // BR-O7, BR-I5: the body is unchanged; header and signature are rebuilt with the current certificate.
        var signed = XmlSigner.Sign(unsigned, key, cert);
        var envelope = SoapEnvelope.Wrap(signed);
        using var activity = MonteFiskalTelemetry.ActivitySource.StartActivity("Send " + stored.Operation);
        ParsedResponse parsed;
        string? raw = null;
        try
        {
            var response = await _transport.SendAsync(stored.Operation, envelope, ct).ConfigureAwait(false);
            raw = response.Body;
            parsed = ResponseParser.Parse(response.StatusCode, response.Body, _options.ResponseSignature);
        }
        catch (FiscalTransportException ex)
        {
            parsed = new ParsedResponse(ExchangeOutcome.Transport, true, Problem: ex.Message);
        }
        MonteFiskalTelemetry.Sent.Add(1);
        await _archive.SaveAsync(stored.Id, "request", signed, ct).ConfigureAwait(false);
        if (raw is not null)
        {
            await _archive.SaveAsync(stored.Id, "response", raw, ct).ConfigureAwait(false);
        }

        var attempt = new Attempt(Now, header.Uuid, parsed.Outcome, parsed.Error?.Code, parsed.Problem, parsed.ResponseUuid);
        var updated = stored with { LastSignedRequest = signed, LastResponse = raw, Attempts = [.. stored.Attempts, attempt] };
        SendResult result;
        switch (parsed.Outcome)
        {
            case ExchangeOutcome.Success:
                updated = updated with { State = InvoiceState.Fiscalized, Result = parsed.Result, ResultAt = Now, LastError = null, NextAttemptAt = null };
                result = new SendResult(InvoiceState.Fiscalized, parsed.Result, null);
                break;
            case ExchangeOutcome.Fault when !parsed.Retryable:
                MonteFiskalTelemetry.Faults.Add(1);
                var error = Hint(parsed.Error!);
                if (stored.Operation == FiscalOperation.RegisterCashDeposit && error.Code == 56 && _options.TreatInitialAlreadyRegisteredAsSuccess)
                {
                    // Q-17 default: an INITIAL that is already registered counts as registered.
                    updated = updated with { State = InvoiceState.Fiscalized, LastError = error, ResultAt = Now };
                    result = new SendResult(InvoiceState.Fiscalized, null, error);
                    break;
                }
                updated = updated with { State = InvoiceState.Rejected, LastError = error, NextAttemptAt = null };
                result = new SendResult(InvoiceState.Rejected, null, error);
                break;
            default:
                MonteFiskalTelemetry.Faults.Add(1);
                var state = Pending(stored);
                var reason = parsed.Outcome == ExchangeOutcome.Transport ? SubsequentDelivery.NoInternet : SubsequentDelivery.Service;
                var delay = TimeSpan.FromSeconds(Math.Min(3600, 30 * Math.Pow(2, Math.Min(updated.Attempts.Count - 1, 7))));
                updated = updated with { State = state, LastError = parsed.Error, SubsequentDelivery = stored.SubsequentDelivery ?? reason, NextAttemptAt = Now + delay };
                result = new SendResult(state, null, parsed.Error, parsed.Problem);
                break;
        }
        await _store.UpdateAsync(updated, ct).ConfigureAwait(false);
        return result;
    }

    private InvoiceState Pending(StoredRequest s) => Now - s.IssuedAt > _options.DeliveryDeadline ? InvoiceState.Overdue : InvoiceState.Pending;

    private FiscalError Hint(FiscalError e) =>
        e.Code == 11 ? e with { Hint = "Schema fault: if the live XSD differs from the bundled revision " + SchemaSet.Revision + ", the schema may have changed on the server (run the schema-drift check)." } : e;

    private async Task<bool> EnsureInitialDepositAsync(DateTimeOffset issuedAt, CancellationToken ct)
    {
        var day = FiscalTime.LocalDate(issuedAt, _profile.TimeZone);
        var zone = FiscalTime.Zone(_profile.TimeZone);
        var start = new DateTimeOffset(day.ToDateTime(TimeOnly.MinValue), zone.GetUtcOffset(day.ToDateTime(TimeOnly.MinValue)));
        var end = start.AddDays(1).AddTicks(-1);
        var deposits = (await _store.ListAsync(start, end, ct).ConfigureAwait(false))
            .Where(r => r.Operation == FiscalOperation.RegisterCashDeposit && r.Tcr == _profile.Tcr && r.Body.Contains("Operation=\"INITIAL\"", StringComparison.Ordinal))
            .ToList();
        if (deposits.Any(d => d.State == InvoiceState.Fiscalized))
        {
            return true;
        }
        var pending = deposits.FirstOrDefault(d => d.State is InvoiceState.Issued or InvoiceState.Pending or InvoiceState.Overdue);
        if (pending is not null)
        {
            var r = await SendStoredAsync(pending, ct).ConfigureAwait(false);
            return r.State == InvoiceState.Fiscalized;
        }
        // BR-D5: INITIAL of 0.00 registered automatically before the first cash invoice of the day.
        var stamp = issuedAt < start ? start : (Now < end && Now >= start ? Now : start);
        var result = await RegisterCashDepositAsync(new CashDeposit
        {
            IssuerTin = _profile.IssuerTin,
            Tcr = _profile.Tcr,
            ChangeDateTime = stamp,
            Operation = CashDepositOperation.Initial,
            Amount = 0m,
        }, ct).ConfigureAwait(false);
        return result.State == InvoiceState.Fiscalized;
    }

    /// <inheritdoc />
    public async Task<FiscalizedInvoice?> IssueAndSendAsync(InvoiceDraft draft, CancellationToken cancellationToken = default)
    {
        var issued = await IssueInvoiceAsync(draft, cancellationToken).ConfigureAwait(false);
        var sent = await SendAsync(issued.Id, cancellationToken).ConfigureAwait(false);
        return sent.State == InvoiceState.Fiscalized && sent.Fic is not null
            ? new FiscalizedInvoice(issued with { State = InvoiceState.Fiscalized }, sent.Fic, Now)
            : null;
    }

    /// <inheritdoc />
    public async Task<IssuedInvoice> IssueCorrectiveAsync(CorrectionRequest request, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(request);
        var original = await _store.GetAsync(request.OriginalId, cancellationToken).ConfigureAwait(false)
                       ?? throw new ArgumentException("Unknown invoice " + request.OriginalId, nameof(request));
        var od = JsonSerializer.Deserialize<InvoiceDraft>(original.DraftJson!, MonteFiskalJson.Options)!;
        // BR-C2: full reversal keeps unit prices positive and negates quantities and payments. BR-C5: partial uses the given lines.
        var lines = request.Lines ?? od.Lines.Select(l => l with { Quantity = -l.Quantity }).ToList();
        var payments = request.Payments ?? od.Payments.Select(p => p with { Amount = p.Amount is { } a ? -a : null }).ToList();
        if (request.Type == CorrectionType.ErrorCorrective && request.Lines is null)
        {
            throw new ArgumentException("ERROR_CORRECTIVE carries all items again with the correct values (BR-C4); pass the corrected lines.", nameof(request));
        }
        var draft = od with
        {
            Kind = InvoiceKind.Corrective,
            IssuedAt = Now,
            Lines = lines,
            Payments = payments,
            Correction = new CorrectionInfo { IicRef = original.Iic!, IssuedAt = OfflineParse(original.IssueDateTime!), Type = request.Type },
        };
        return await IssueInvoiceAsync(draft, cancellationToken).ConfigureAwait(false);
    }

    private static DateTimeOffset OfflineParse(string s) => DateTimeOffset.Parse(s, CultureInfo.InvariantCulture);

    /// <inheritdoc />
    public async Task<TcrRegistrationResult> RegisterTcrAsync(TcrRegistration registration, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(registration);
        var today = FiscalTime.LocalDate(Now, _profile.TimeZone);
        var v = TcrValidator.Validate(registration, today, isNewRegistration: string.IsNullOrEmpty(registration.ValidTo));
        if (!v.IsValid)
        {
            throw new FiscalValidationException(v);
        }
        var body = RequestBuilder.Tcr(registration).ToString();
        var probe = RequestBuilder.Root("RegisterTCRRequest", RequestBuilder.Header(new RequestHeader(_uuids.NewUuid(), Now), _profile.TimeZone, _options), XNode.FromRaw(body)).ToString();
        var schema = SchemaSet.ValidateUnsigned(probe);
        if (schema.Count > 0)
        {
            throw new FiscalValidationException(new ValidationResult(schema.Select(e => new Finding("SCHEMA", Severity.Error, "RegisterTCRRequest", e)).ToList()));
        }
        var stored = await _store.AddAsync(new StoredRequest
        {
            Id = _uuids.NewUuid(),
            Operation = FiscalOperation.RegisterTcr,
            Body = body,
            IssuerTin = registration.IssuerTin,
            BusinessUnit = registration.BusinessUnit,
            IssuedAt = Now,
        }, cancellationToken).ConfigureAwait(false);
        var r = await SendStoredAsync(stored, cancellationToken).ConfigureAwait(false);
        return new TcrRegistrationResult(r.State == InvoiceState.Fiscalized, r.Fic, r.Error, r.Problem);
    }

    /// <inheritdoc />
    public Task<TcrRegistrationResult> DeregisterTcrAsync(string tcrInternalId, DateOnly validTo, CancellationToken cancellationToken = default) =>
        // BR-R4: deregistration sends ValidTo = today; the same TCRIntID returns the same TCR code (BR-R3).
        RegisterTcrAsync(new TcrRegistration
        {
            IssuerTin = _profile.IssuerTin,
            BusinessUnit = _profile.BusinessUnit,
            InternalId = tcrInternalId,
            Software = _profile.Software,
            Maintainer = _profile.Maintainer,
            ValidTo = validTo.ToString("yyyy-MM-dd", CultureInfo.InvariantCulture),
        }, cancellationToken);

    /// <inheritdoc />
    public async Task<CashDepositResult> RegisterCashDepositAsync(CashDeposit deposit, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(deposit);
        deposit = deposit with { ChangeDateTime = deposit.ChangeDateTime ?? Now };
        var v = DepositValidator.Validate(deposit);
        if (!v.IsValid)
        {
            throw new FiscalValidationException(v);
        }
        var body = RequestBuilder.Deposit(deposit, _profile.TimeZone, _options).ToString();
        var stored = await _store.AddAsync(new StoredRequest
        {
            Id = _uuids.NewUuid(),
            Operation = FiscalOperation.RegisterCashDeposit,
            Body = body,
            IssuerTin = deposit.IssuerTin,
            BusinessUnit = _profile.BusinessUnit,
            Tcr = deposit.Tcr,
            IssuedAt = deposit.ChangeDateTime!.Value,
            Total = Fmt.Money(deposit.Amount),
        }, cancellationToken).ConfigureAwait(false);
        var r = await SendStoredAsync(stored, cancellationToken).ConfigureAwait(false);
        return new CashDepositResult(stored.Id, r.State, r.Fic, r.Error, r.Problem);
    }

    /// <inheritdoc />
    public async Task<ResendSummary> ResendPendingAsync(ResendOptions options, CancellationToken cancellationToken = default)
    {
        ArgumentNullException.ThrowIfNull(options);
        var started = Now;
        var pending = await _store.PendingAsync(options.MaxItems, cancellationToken).ConfigureAwait(false);
        int attempted = 0, fiscalized = 0, rejected = 0, still = 0;
        // BR-O8: oldest first, within a budget so that live issuing is not delayed.
        foreach (var p in pending.OrderBy(p => p.Operation == FiscalOperation.RegisterInvoice ? 1 : 0).ThenBy(p => p.IssuedAt))
        {
            if (options.Budget is { } b && Now - started > b)
            {
                break;
            }
            if (p.NextAttemptAt is { } next && next > Now)
            {
                still++;
                continue;
            }
            var current = await _store.GetAsync(p.Id, cancellationToken).ConfigureAwait(false);
            if (current is null || current.State is InvoiceState.Fiscalized or InvoiceState.Rejected)
            {
                continue;
            }
            attempted++;
            var r = await SendStoredAsync(current, cancellationToken).ConfigureAwait(false);
            switch (r.State)
            {
                case InvoiceState.Fiscalized: fiscalized++; break;
                case InvoiceState.Rejected: rejected++; break;
                default: still++; break;
            }
        }
        return new ResendSummary(attempted, fiscalized, still, rejected);
    }

    /// <summary>
    /// Signs a stored request for the offline export (BR-O11): the body is unchanged, the header gets a new UUID,
    /// the current time and <paramref name="reason"/> as <c>SubseqDelivType</c>.
    /// </summary>
    public string SignForExport(StoredRequest stored, SubsequentDelivery reason = SubsequentDelivery.NoInternet)
    {
        ArgumentNullException.ThrowIfNull(stored);
        var cert = _certificates.GetCertificate();
        using var key = cert.GetRSAPrivateKey() ?? throw new FiscalConfigurationException("The certificate has no RSA private key.");
        var header = new RequestHeader(_uuids.NewUuid(), Now, reason);
        var unsigned = stored.Operation switch
        {
            FiscalOperation.RegisterInvoice => RequestBuilder.InvoiceRequest(stored.Body, header, _profile.TimeZone, _options),
            FiscalOperation.RegisterCashDeposit => RequestBuilder.Root("RegisterCashDepositRequest", RequestBuilder.Header(header, _profile.TimeZone, _options), XNode.FromRaw(stored.Body)).ToString(),
            _ => throw new ArgumentException("Only invoices and cash deposits are exported.", nameof(stored)),
        };
        return XmlSigner.Sign(unsigned, key, cert);
    }

    /// <summary>Records a JIKR received through the offline import (BR-O11).</summary>
    public async Task<bool> ApplyImportedResponseAsync(string iic, string responseXml, CancellationToken cancellationToken = default)
    {
        var stored = await _store.FindByIicAsync(iic, cancellationToken).ConfigureAwait(false);
        if (stored is null)
        {
            return false;
        }
        ArgumentNullException.ThrowIfNull(responseXml);
        if (responseXml.StartsWith("<?xml", StringComparison.Ordinal))
        {
            responseXml = responseXml[(responseXml.IndexOf("?>", StringComparison.Ordinal) + 2)..].TrimStart();
        }
        var body = responseXml.Contains(":Envelope", StringComparison.Ordinal) ? responseXml
            : "<s:Envelope xmlns:s=\"http://schemas.xmlsoap.org/soap/envelope/\"><s:Body>" + responseXml + "</s:Body></s:Envelope>";
        var parsed = ResponseParser.Parse(200, body, _options.ResponseSignature);
        if (parsed.Outcome != ExchangeOutcome.Success)
        {
            return false;
        }
        await _store.UpdateAsync(stored with { State = InvoiceState.Fiscalized, Result = parsed.Result, ResultAt = Now, LastResponse = responseXml }, cancellationToken).ConfigureAwait(false);
        return true;
    }

    /// <inheritdoc />
    public IkofCheck RecomputeIkof(IkofParameters parameters)
    {
        using var key = _certificates.GetCertificate().GetRSAPrivateKey() ?? throw new FiscalConfigurationException("The certificate has no RSA private key.");
        return Ikof.Compute(parameters, key);
    }

    /// <inheritdoc />
    public string BuildVerificationUrl(IkofParameters parameters, string iic) =>
        Verification.VerificationUrl.Build(_options.VerificationBaseOverride?.ToString() ?? Verification.VerificationUrl.BaseFor(_profile.Environment), iic, parameters, _options.VerificationUrlEncoding);
}

using MonteFiskal.Model;

namespace MonteFiskal.Validation;

public enum Severity
{
    Warning,
    Error,
}

/// <summary>One finding, keyed by the rule ID of <c>docs/analysis/business-rules.md</c> or <c>SCHEMA</c>.</summary>
public sealed record Finding(string Rule, Severity Severity, string Path, string Message)
{
    public override string ToString() => $"{Rule} {Severity.ToString().ToLowerInvariant()} at {Path}: {Message}";
}

/// <summary>Findings of a validation run. Blocking only when an error is present (architecture §1, principle 5).</summary>
public sealed record ValidationResult(IReadOnlyList<Finding> Findings)
{
    public bool IsValid => Findings.All(f => f.Severity != Severity.Error);
    public IEnumerable<Finding> Errors => Findings.Where(f => f.Severity == Severity.Error);
    public IEnumerable<Finding> Warnings => Findings.Where(f => f.Severity == Severity.Warning);
}

/// <summary>A blocking local rule failed; nothing was issued and no ordinal was consumed (architecture §2.4).</summary>
public sealed class FiscalValidationException : Exception
{
    public FiscalValidationException(ValidationResult result)
        : base("Validation failed: " + string.Join("; ", (result ?? throw new ArgumentNullException(nameof(result))).Errors))
    {
        Result = result;
    }

    public FiscalValidationException() : base("Validation failed.") => Result = new ValidationResult([]);
    public FiscalValidationException(string message) : base(message) => Result = new ValidationResult([]);
    public FiscalValidationException(string message, Exception inner) : base(message, inner) => Result = new ValidationResult([]);

    public ValidationResult Result { get; }
}

internal sealed class FindingList
{
    private readonly List<Finding> _items = [];

    public void Error(string rule, string path, string message) => _items.Add(new Finding(rule, Severity.Error, path, message));
    public void Warn(string rule, string path, string message) => _items.Add(new Finding(rule, Severity.Warning, path, message));
    public bool HasErrors => _items.Any(f => f.Severity == Severity.Error);
    public ValidationResult Result() => new(_items.ToList());
}

/// <summary>Business rules for cash deposits (BR-D1, BR-D2).</summary>
public static class DepositValidator
{
    public static ValidationResult Validate(CashDeposit deposit)
    {
        ArgumentNullException.ThrowIfNull(deposit);
        var f = new FindingList();
        // BR-D2: a withdrawal cannot be zero; amounts are never negative. BR-D1 allows an INITIAL of 0.00.
        if (deposit.Amount < 0m)
        {
            f.Error("BR-D2", "CashDeposit/@CashAmt", "The amount of a cash deposit cannot be negative.");
        }
        else if (deposit.Operation == CashDepositOperation.Withdraw && deposit.Amount == 0m)
        {
            f.Error("BR-D2", "CashDeposit/@CashAmt", "A WITHDRAW of 0.00 is refused.");
        }
        return f.Result();
    }
}

/// <summary>Business rules for TCR registration (BR-R2, BR-R4).</summary>
public static class TcrValidator
{
    public static ValidationResult Validate(TcrRegistration registration, DateOnly today, bool isNewRegistration = true)
    {
        ArgumentNullException.ThrowIfNull(registration);
        var f = new FindingList();
        // BR-R2: software and maintainer codes are mandatory for a new registration.
        if (isNewRegistration && (string.IsNullOrEmpty(registration.Software) || string.IsNullOrEmpty(registration.Maintainer)))
        {
            f.Error("BR-R2", "TCR/@SoftCode", "A new TCR registration needs the software code and the maintainer code.");
        }
        // BR-R4: ValidFrom and ValidTo cannot be in the past and ValidTo cannot precede ValidFrom.
        DateOnly? from = Parse(registration.ValidFrom);
        DateOnly? to = Parse(registration.ValidTo);
        if (from is { } a && a < today)
        {
            f.Error("BR-R4", "TCR/@ValidFrom", "ValidFrom is in the past.");
        }
        if (to is { } b && b < today)
        {
            f.Error("BR-R4", "TCR/@ValidTo", "ValidTo is in the past.");
        }
        if (from is { } x && to is { } y && y < x)
        {
            f.Error("BR-R4", "TCR/@ValidTo", "ValidTo precedes ValidFrom.");
        }
        return f.Result();
    }

    private static DateOnly? Parse(string? s) =>
        s is not null && DateOnly.TryParseExact(s, "yyyy-MM-dd", System.Globalization.CultureInfo.InvariantCulture, System.Globalization.DateTimeStyles.None, out var d) ? d : null;
}

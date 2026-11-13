using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.DependencyInjection.Extensions;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Options;
using MonteFiskal.Archive;
using MonteFiskal.Outbox;
using MonteFiskal.Security;
using MonteFiskal.Transport;

namespace MonteFiskal.Extensions.DependencyInjection;

/// <summary>Where the PKCS#12 certificate and its password come from. The password is never stored in configuration files.</summary>
public sealed class CertificateSettings
{
    /// <summary>Path of the .p12/.pfx file, outside the repository and the web root.</summary>
    public string? Path { get; set; }

    /// <summary>Name of the environment variable holding the password (preferred).</summary>
    public string? PasswordEnvironmentVariable { get; set; } = "MONTEFISKAL_CERT_PASSWORD";

    /// <summary>Alternatively, a file readable only by the service account.</summary>
    public string? PasswordFile { get; set; }
}

/// <summary>Configuration section <c>MonteFiskal</c>.</summary>
public sealed class MonteFiskalSettings
{
    public FiscalProfile? Profile { get; set; }
    public MonteFiskalOptions Options { get; set; } = new();
    public CertificateSettings Certificate { get; set; } = new();

    /// <summary>Run the background resend of pending requests (BR-O8). Default true.</summary>
    public bool BackgroundResend { get; set; } = true;

    public TimeSpan ResendInterval { get; set; } = TimeSpan.FromMinutes(1);
}

public static class ServiceCollectionExtensions
{
    public const string HttpClientName = "MonteFiskal";

    /// <summary>Registers <see cref="IFiscalClient"/> from the configuration section <c>MonteFiskal</c>.</summary>
    public static IServiceCollection AddMonteFiskal(this IServiceCollection services, IConfiguration configuration)
    {
        ArgumentNullException.ThrowIfNull(configuration);
        return services.AddMonteFiskal(s => configuration.Bind(s));
    }

    /// <summary>Registers <see cref="IFiscalClient"/>, the HTTP transport, the outbox (in memory unless one is registered) and the resend service.</summary>
    public static IServiceCollection AddMonteFiskal(this IServiceCollection services, Action<MonteFiskalSettings> configure)
    {
        ArgumentNullException.ThrowIfNull(services);
        services.Configure(configure);
        services.TryAddSingleton(TimeProvider.System);
        services.TryAddSingleton<IOutboxStore, InMemoryOutboxStore>();
        services.TryAddSingleton<IArchiveStore>(NullArchiveStore.Instance);
        services.TryAddSingleton<IUuidGenerator>(RandomUuidGenerator.Instance);
        services.TryAddSingleton<ICertificateProvider>(sp =>
        {
            var c = sp.GetRequiredService<IOptions<MonteFiskalSettings>>().Value.Certificate;
            if (string.IsNullOrEmpty(c.Path))
            {
                throw new FiscalConfigurationException("MonteFiskal:Certificate:Path is not set.");
            }
            return !string.IsNullOrEmpty(c.PasswordFile)
                ? Pkcs12CertificateProvider.FromPasswordFile(c.Path, c.PasswordFile)
                : Pkcs12CertificateProvider.FromEnvironment(c.Path, c.PasswordEnvironmentVariable ?? "MONTEFISKAL_CERT_PASSWORD");
        });
        services.AddHttpClient(HttpClientName);
        services.TryAddSingleton<IFiscalTransport>(sp =>
        {
            var s = sp.GetRequiredService<IOptions<MonteFiskalSettings>>().Value;
            var profile = s.Profile ?? throw new FiscalConfigurationException("MonteFiskal:Profile is not set.");
            var endpoint = s.Options.ServiceEndpointOverride ?? FiscalEndpoints.For(profile.Environment);
            return new HttpFiscalTransport(sp.GetRequiredService<IHttpClientFactory>().CreateClient(HttpClientName), endpoint, s.Options);
        });
        services.TryAddSingleton<IFiscalClient>(sp =>
        {
            var s = sp.GetRequiredService<IOptions<MonteFiskalSettings>>().Value;
            var profile = s.Profile ?? throw new FiscalConfigurationException("MonteFiskal:Profile is not set.");
            return new FiscalClient(profile, sp.GetRequiredService<ICertificateProvider>(), sp.GetRequiredService<IFiscalTransport>(),
                sp.GetRequiredService<IOutboxStore>(), s.Options, sp.GetRequiredService<TimeProvider>(), sp.GetRequiredService<IUuidGenerator>(),
                archive: sp.GetRequiredService<IArchiveStore>());
        });
        services.AddHostedService<ResendBackgroundService>();
        return services;
    }
}

/// <summary>Resends pending requests in the background, oldest first, within a budget (BR-O8, BR-O12).</summary>
public sealed partial class ResendBackgroundService(IServiceProvider services, IOptions<MonteFiskalSettings> settings, ILogger<ResendBackgroundService> logger, TimeProvider clock) : BackgroundService
{
    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        var s = settings.Value;
        if (!s.BackgroundResend || s.Profile is null)
        {
            return;
        }
        var client = services.GetRequiredService<IFiscalClient>();
        using var timer = new PeriodicTimer(s.ResendInterval, clock);
        do
        {
            try
            {
                var r = await client.ResendPendingAsync(new ResendOptions(50, TimeSpan.FromSeconds(30)), stoppingToken).ConfigureAwait(false);
                if (r.Attempted > 0)
                {
                    LogResend(r.Attempted, r.Fiscalized, r.StillPending, r.Rejected);
                }
                if (r.Rejected > 0)
                {
                    LogRejected(r.Rejected);
                }
            }
            catch (OperationCanceledException) when (stoppingToken.IsCancellationRequested)
            {
                break;
            }
            catch (Exception ex)
            {
                LogFailure(ex);
            }
        }
        while (await timer.WaitForNextTickAsync(stoppingToken).ConfigureAwait(false));
    }

    [LoggerMessage(Level = LogLevel.Information, Message = "MonteFiskal resend: {Attempted} attempted, {Fiscalized} fiscalized, {Pending} pending, {Rejected} rejected")]
    private partial void LogResend(int attempted, int fiscalized, int pending, int rejected);

    [LoggerMessage(Level = LogLevel.Error, Message = "MonteFiskal: {Rejected} request(s) were rejected by the fiscal service and need a person")]
    private partial void LogRejected(int rejected);

    [LoggerMessage(Level = LogLevel.Error, Message = "MonteFiskal resend failed")]
    private partial void LogFailure(Exception ex);
}

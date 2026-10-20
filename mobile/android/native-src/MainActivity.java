package net.lowpartners.optica;

import android.app.AlertDialog;
import android.app.DownloadManager;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.database.Cursor;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import android.webkit.CookieManager;
import android.webkit.URLUtil;
import android.webkit.WebView;
import android.widget.Toast;
import androidx.activity.OnBackPressedCallback;
import com.getcapacitor.BridgeActivity;
import java.util.HashMap;
import java.util.HashSet;
import java.util.Map;
import java.util.Set;

public class MainActivity extends BridgeActivity {

    private final Map<Long, String> pendingDownloadNames = new HashMap<>();
    private final Set<Long> handledDownloadIds = new HashSet<>();
    private final Handler mainHandler = new Handler(Looper.getMainLooper());
    private BroadcastReceiver downloadCompleteReceiver;
    private Runnable downloadPollRunnable;
    private boolean downloadListenerAttached;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        registerPlugin(OpticaSharePlugin.class);
        super.onCreate(savedInstanceState);

        registerDownloadCompleteReceiver();
        // WebView často ještě není ready hned po super.onCreate — retry.
        attachDownloadListenerWhenReady(0);

        /*
         * Systémová šipka «zpět»: v historii WebView jako v prohlížeči
         * (filtr → karta cívky → zpět na výsledky filtru). Bez historie = výchozí (app na pozadí).
         */
        getOnBackPressedDispatcher().addCallback(
            this,
            new OnBackPressedCallback(true) {
                @Override
                public void handleOnBackPressed() {
                    WebView webView = getBridge() != null ? getBridge().getWebView() : null;
                    if (webView != null && webView.canGoBack()) {
                        webView.goBack();
                        return;
                    }
                    setEnabled(false);
                    getOnBackPressedDispatcher().onBackPressed();
                    setEnabled(true);
                }
            }
        );
    }

    @Override
    public void onResume() {
        super.onResume();
        attachDownloadListenerWhenReady(0);
    }

    @Override
    public void onDestroy() {
        if (downloadPollRunnable != null) {
            mainHandler.removeCallbacks(downloadPollRunnable);
            downloadPollRunnable = null;
        }
        if (downloadCompleteReceiver != null) {
            try {
                unregisterReceiver(downloadCompleteReceiver);
            } catch (Exception ignored) {
            }
            downloadCompleteReceiver = null;
        }
        super.onDestroy();
    }

    private void attachDownloadListenerWhenReady(int attempt) {
        if (downloadListenerAttached) {
            return;
        }
        WebView webView = getBridge() != null ? getBridge().getWebView() : null;
        if (webView == null) {
            if (attempt < 40) {
                mainHandler.postDelayed(() -> attachDownloadListenerWhenReady(attempt + 1), 250);
            }
            return;
        }
        setupWebViewDownloads(webView);
        downloadListenerAttached = true;
    }

    private void registerDownloadCompleteReceiver() {
        downloadCompleteReceiver =
            new BroadcastReceiver() {
                @Override
                public void onReceive(Context context, Intent intent) {
                    if (!DownloadManager.ACTION_DOWNLOAD_COMPLETE.equals(intent.getAction())) {
                        return;
                    }
                    long id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1L);
                    onDownloadFinished(id);
                }
            };

        IntentFilter filter = new IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE);
        // DOWNLOAD_COMPLETE posílá systém (jiný process) → musí být EXPORTED (API 33+).
        if (Build.VERSION.SDK_INT >= 33) {
            registerReceiver(downloadCompleteReceiver, filter, Context.RECEIVER_EXPORTED);
        } else {
            registerReceiver(downloadCompleteReceiver, filter);
        }
    }

    /**
     * WebView defaultně stahování ignoruje — bez listeneru odkaz na APK v aplikaci nic neudělá.
     * DownloadManager + cookies → Stažené; po dokončení dialog Instalovat / otevřít Stažené.
     */
    private void setupWebViewDownloads(WebView webView) {
        CookieManager cookieManager = CookieManager.getInstance();
        cookieManager.setAcceptCookie(true);
        cookieManager.setAcceptThirdPartyCookies(webView, true);

        webView.setDownloadListener(
            (url, userAgent, contentDisposition, mimeType, contentLength) -> {
                try {
                    DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
                    String type = (mimeType != null && !mimeType.isEmpty())
                        ? mimeType
                        : "application/octet-stream";
                    request.setMimeType(type);
                    request.addRequestHeader("User-Agent", userAgent);

                    String cookies = cookieManager.getCookie(url);
                    if (cookies != null && !cookies.isEmpty()) {
                        request.addRequestHeader("Cookie", cookies);
                    }

                    String fileName = URLUtil.guessFileName(url, contentDisposition, type);
                    if (fileName == null || fileName.isEmpty()) {
                        fileName = "optica-sklad.apk";
                    }

                    request.setTitle(fileName);
                    request.setDescription("Optický sklad — stahování");
                    request.setNotificationVisibility(
                        DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED
                    );
                    request.setDestinationInExternalPublicDir(
                        Environment.DIRECTORY_DOWNLOADS,
                        fileName
                    );

                    DownloadManager dm = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
                    if (dm == null) {
                        Toast.makeText(this, "Stahování není dostupné.", Toast.LENGTH_LONG).show();
                        return;
                    }
                    long id = dm.enqueue(request);
                    pendingDownloadNames.put(id, fileName);
                    startDownloadPolling();
                    Toast.makeText(
                        this,
                        "Stahuji " + fileName + "…\nPo dokončení nabídnu instalaci.",
                        Toast.LENGTH_LONG
                    ).show();
                } catch (Exception e) {
                    Toast.makeText(
                        this,
                        "Stahování selhalo: " + e.getMessage(),
                        Toast.LENGTH_LONG
                    ).show();
                }
            }
        );
    }

    /** Záloha, když OEM nedoručí ACTION_DOWNLOAD_COMPLETE. */
    private void startDownloadPolling() {
        if (downloadPollRunnable != null) {
            return;
        }
        downloadPollRunnable =
            new Runnable() {
                @Override
                public void run() {
                    if (pendingDownloadNames.isEmpty()) {
                        downloadPollRunnable = null;
                        return;
                    }
                    Long[] ids = pendingDownloadNames.keySet().toArray(new Long[0]);
                    for (Long id : ids) {
                        int status = getDownloadStatus(id);
                        if (status == DownloadManager.STATUS_SUCCESSFUL ||
                            status == DownloadManager.STATUS_FAILED) {
                            onDownloadFinished(id);
                        }
                    }
                    if (!pendingDownloadNames.isEmpty()) {
                        mainHandler.postDelayed(this, 800);
                    } else {
                        downloadPollRunnable = null;
                    }
                }
            };
        mainHandler.postDelayed(downloadPollRunnable, 800);
    }

    private void onDownloadFinished(long id) {
        if (id < 0 || !pendingDownloadNames.containsKey(id)) {
            return;
        }
        if (!handledDownloadIds.add(id)) {
            return;
        }
        String fileName = pendingDownloadNames.remove(id);
        if (!isDownloadSuccessful(id)) {
            Toast.makeText(
                MainActivity.this,
                "Stahování selhalo: " + fileName,
                Toast.LENGTH_LONG
            ).show();
            return;
        }
        showDownloadReadyDialog(id, fileName);
    }

    private int getDownloadStatus(long downloadId) {
        DownloadManager dm = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
        if (dm == null) {
            return -1;
        }
        try (Cursor c = dm.query(new DownloadManager.Query().setFilterById(downloadId))) {
            if (c == null || !c.moveToFirst()) {
                return -1;
            }
            int statusIdx = c.getColumnIndex(DownloadManager.COLUMN_STATUS);
            return statusIdx >= 0 ? c.getInt(statusIdx) : -1;
        } catch (Exception e) {
            return -1;
        }
    }

    private boolean isDownloadSuccessful(long downloadId) {
        return getDownloadStatus(downloadId) == DownloadManager.STATUS_SUCCESSFUL;
    }

    private void showDownloadReadyDialog(long downloadId, String fileName) {
        String pathHint = Environment.DIRECTORY_DOWNLOADS + "/" + fileName;
        runOnUiThread(
            () -> {
                if (isFinishing()) {
                    return;
                }
                new AlertDialog.Builder(this)
                    .setTitle("Stažení hotovo")
                    .setMessage(
                        "Soubor: " +
                        fileName +
                        "\n\nUmístění: " +
                        pathHint +
                        "\n(ve správci souborů: Stažené / Downloads)\n\nNainstalovat aktualizaci teď?"
                    )
                    .setPositiveButton(
                        "Nainstalovat",
                        (d, w) -> openDownloadedApk(downloadId)
                    )
                    .setNeutralButton("Otevřít Stažené", (d, w) -> openDownloadsFolder())
                    .setNegativeButton("Zavřít", null)
                    .setCancelable(true)
                    .show();
            }
        );
    }

    private void openDownloadsFolder() {
        try {
            Intent intent = new Intent(DownloadManager.ACTION_VIEW_DOWNLOADS);
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            startActivity(intent);
        } catch (Exception e) {
            Toast.makeText(
                this,
                "Otevřete Stažené / Downloads ve správci souborů.",
                Toast.LENGTH_LONG
            ).show();
        }
    }

    private void openDownloadedApk(long downloadId) {
        DownloadManager dm = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
        if (dm == null) {
            return;
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            if (!getPackageManager().canRequestPackageInstalls()) {
                Toast.makeText(
                    this,
                    "Povolte instalaci z této aplikace (neznámé zdroje), pak znovu Nainstalovat ze Stažených.",
                    Toast.LENGTH_LONG
                ).show();
                try {
                    Intent settings = new Intent(
                        Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                        Uri.parse("package:" + getPackageName())
                    );
                    settings.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                    startActivity(settings);
                } catch (Exception ignored) {
                }
                return;
            }
        }

        try {
            Uri uri = dm.getUriForDownloadedFile(downloadId);
            if (uri == null) {
                Toast.makeText(this, "Soubor nenalezen ve Stažených.", Toast.LENGTH_LONG).show();
                openDownloadsFolder();
                return;
            }
            Intent intent = new Intent(Intent.ACTION_VIEW);
            intent.setDataAndType(uri, "application/vnd.android.package-archive");
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
            startActivity(intent);
        } catch (Exception e) {
            Toast.makeText(
                this,
                "Nelze otevřít instalátor: " + e.getMessage() + " — použijte Stažené.",
                Toast.LENGTH_LONG
            ).show();
            openDownloadsFolder();
        }
    }
}

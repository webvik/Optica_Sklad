package net.lowpartners.optica;

import android.content.ClipData;
import android.content.Intent;
import android.net.Uri;
import android.util.Base64;
import android.webkit.CookieManager;
import androidx.core.content.FileProvider;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;

@CapacitorPlugin(name = "OpticaShare")
public class OpticaSharePlugin extends Plugin {

    private File pendingShareFile = null;

    @PluginMethod
    public void sharePdfFromUrl(PluginCall call) {
        String urlString = call.getString("url");
        String filename = call.getString("filename", "dokument.pdf");
        String dialogTitle = call.getString("dialogTitle", "Sdílet");

        if (urlString == null || urlString.isEmpty()) {
            call.reject("NO_URL");
            return;
        }

        call.setKeepAlive(true);
        new Thread(
            () -> {
                try {
                    URL url = new URL(urlString);
                    HttpURLConnection conn = (HttpURLConnection) url.openConnection();
                    conn.setRequestMethod("GET");
                    conn.setConnectTimeout(30000);
                    conn.setReadTimeout(120000);
                    conn.setInstanceFollowRedirects(true);

                    String cookies = CookieManager.getInstance().getCookie(urlString);
                    if (cookies != null && !cookies.isEmpty()) {
                        conn.setRequestProperty("Cookie", cookies);
                    }

                    int code = conn.getResponseCode();
                    if (code >= 400) {
                        call.reject("HTTP " + code);
                        return;
                    }

                    String safeName = sanitizeFilename(filename);
                    File file = new File(getContext().getCacheDir(), safeName);
                    try (InputStream in = conn.getInputStream(); FileOutputStream out = new FileOutputStream(file, false)) {
                        byte[] buffer = new byte[8192];
                        int read;
                        while ((read = in.read(buffer)) != -1) {
                            out.write(buffer, 0, read);
                        }
                    } finally {
                        conn.disconnect();
                    }

                    if (file.length() < 32) {
                        call.reject("EMPTY_PDF");
                        return;
                    }

                    getActivity()
                        .runOnUiThread(
                            () -> {
                                try {
                                    launchShare(file, dialogTitle);
                                    call.resolve();
                                } catch (Exception ex) {
                                    call.reject(ex.getMessage() != null ? ex.getMessage() : "SHARE_FAILED");
                                }
                            }
                        );
                } catch (Exception ex) {
                    call.reject(ex.getMessage() != null ? ex.getMessage() : "DOWNLOAD_FAILED");
                }
            }
        )
            .start();
    }

    @PluginMethod
    public void sharePdfBegin(PluginCall call) {
        String filename = call.getString("filename", "dokument.pdf");
        pendingShareFile = new File(getContext().getCacheDir(), sanitizeFilename(filename));
        if (pendingShareFile.exists()) {
            //noinspection ResultOfMethodCallIgnored
            pendingShareFile.delete();
        }
        call.resolve();
    }

    @PluginMethod
    public void sharePdfAppend(PluginCall call) {
        String data = call.getString("data");
        if (pendingShareFile == null) {
            call.reject("NO_BEGIN");
            return;
        }
        if (data == null || data.isEmpty()) {
            call.reject("NO_DATA");
            return;
        }

        try {
            if (data.contains(",")) {
                data = data.split(",", 2)[1];
            }
            byte[] bytes = Base64.decode(data, Base64.DEFAULT);
            boolean append = pendingShareFile.exists();
            try (FileOutputStream fos = new FileOutputStream(pendingShareFile, append)) {
                fos.write(bytes);
            }
            call.resolve();
        } catch (Exception ex) {
            call.reject(ex.getMessage() != null ? ex.getMessage() : "APPEND_FAILED");
        }
    }

    @PluginMethod
    public void sharePdfFinish(PluginCall call) {
        String dialogTitle = call.getString("dialogTitle", "Sdílet");
        if (pendingShareFile == null || !pendingShareFile.exists() || pendingShareFile.length() < 32) {
            call.reject("NO_FILE");
            return;
        }

        try {
            launchShare(pendingShareFile, dialogTitle);
            pendingShareFile = null;
            call.resolve();
        } catch (Exception ex) {
            call.reject(ex.getMessage() != null ? ex.getMessage() : "SHARE_FAILED");
        }
    }

    @PluginMethod
    public void sharePdf(PluginCall call) {
        String filename = call.getString("filename", "dokument.pdf");
        String data = call.getString("data");
        String dialogTitle = call.getString("dialogTitle", "Sdílet");

        if (data == null || data.isEmpty()) {
            call.reject("NO_DATA");
            return;
        }

        try {
            if (data.contains(",")) {
                data = data.split(",", 2)[1];
            }

            byte[] bytes = Base64.decode(data, Base64.DEFAULT);
            File file = new File(getContext().getCacheDir(), sanitizeFilename(filename));
            try (FileOutputStream fos = new FileOutputStream(file, false)) {
                fos.write(bytes);
            }

            launchShare(file, dialogTitle);
            call.resolve();
        } catch (Exception ex) {
            call.reject(ex.getMessage() != null ? ex.getMessage() : "SHARE_FAILED");
        }
    }

    private static String sanitizeFilename(String filename) {
        String safeName = filename.replaceAll("[^\\w.\\-]", "_");
        if (safeName.isEmpty()) {
            safeName = "dokument.pdf";
        }
        return safeName;
    }

    private void launchShare(File file, String dialogTitle) {
        Uri uri = FileProvider.getUriForFile(getContext(), getContext().getPackageName() + ".fileprovider", file);

        Intent intent = new Intent(Intent.ACTION_SEND);
        intent.setType("application/pdf");
        intent.putExtra(Intent.EXTRA_STREAM, uri);
        intent.setClipData(ClipData.newRawUri("", uri));
        intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);

        Intent chooser = Intent.createChooser(intent, dialogTitle);
        chooser.addCategory(Intent.CATEGORY_DEFAULT);
        getActivity().startActivity(chooser);
    }
}

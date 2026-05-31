package net.lowpartners.optica;

import android.content.ClipData;
import android.content.Intent;
import android.net.Uri;
import android.util.Base64;
import androidx.core.content.FileProvider;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.io.File;
import java.io.FileOutputStream;

@CapacitorPlugin(name = "OpticaShare")
public class OpticaSharePlugin extends Plugin {

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
            String safeName = filename.replaceAll("[^\\w.\\-]", "_");
            if (safeName.isEmpty()) {
                safeName = "dokument.pdf";
            }

            File cacheDir = getContext().getCacheDir();
            File file = new File(cacheDir, safeName);
            try (FileOutputStream fos = new FileOutputStream(file, false)) {
                fos.write(bytes);
            }

            Uri uri = FileProvider.getUriForFile(
                getContext(),
                getContext().getPackageName() + ".fileprovider",
                file
            );

            Intent intent = new Intent(Intent.ACTION_SEND);
            intent.setType("application/pdf");
            intent.putExtra(Intent.EXTRA_STREAM, uri);
            intent.setClipData(ClipData.newRawUri("", uri));
            intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);

            Intent chooser = Intent.createChooser(intent, dialogTitle);
            chooser.addCategory(Intent.CATEGORY_DEFAULT);
            getActivity().startActivity(chooser);
            call.resolve();
        } catch (Exception ex) {
            call.reject(ex.getMessage() != null ? ex.getMessage() : "SHARE_FAILED");
        }
    }
}

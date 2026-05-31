package net.lowpartners.optica;

import android.os.Bundle;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        registerPlugin(OpticaSharePlugin.class);
        super.onCreate(savedInstanceState);
    }
}

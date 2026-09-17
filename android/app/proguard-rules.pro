# SPDX-FileCopyrightText: 2026 Nissaar
# SPDX-License-Identifier: AGPL-3.0-or-later

# kotlinx.serialization generates serializers as companion objects and looks them up
# reflectively; R8 cannot see those links and would strip them.
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.**
-keepclassmembers class xyz.photosweep.** {
    *** Companion;
}
-keepclasseswithmembers class xyz.photosweep.** {
    kotlinx.serialization.KSerializer serializer(...);
}
-keep,includedescriptorclasses class xyz.photosweep.api.**$$serializer { *; }
-keep,includedescriptorclasses class xyz.photosweep.data.**$$serializer { *; }

# Tink, pulled in by androidx.security.crypto, is compiled against ErrorProne
# annotations that are not on the runtime classpath.
-dontwarn com.google.errorprone.annotations.**
-dontwarn javax.annotation.**

# OkHttp references optional platform integrations that are not present on Android.
-dontwarn okhttp3.internal.platform.**
-dontwarn org.conscrypt.**
-dontwarn org.bouncycastle.**
-dontwarn org.openjsse.**

# Release builds should carry no logging at all.
-assumenosideeffects class android.util.Log {
    public static *** d(...);
    public static *** v(...);
    public static *** i(...);
    public static *** w(...);
}

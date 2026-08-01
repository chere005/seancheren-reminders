# kotlinx.serialization: keep the generated serializers for our model types.
-keepattributes *Annotation*, InnerClasses
-dontnote kotlinx.serialization.**
-keepclassmembers class com.seancheren.suite.core.** {
    *** Companion;
}
-keepclasseswithmembers class com.seancheren.suite.core.** {
    kotlinx.serialization.KSerializer serializer(...);
}

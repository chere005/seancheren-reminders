// Root build file. The two modules apply their plugins from the version catalog;
// nothing is applied at the root. Mirrors the two-layer split: :core (pure JVM
// logic, a cousin of ios/Shared) and :app (Compose UI, a cousin of ios/App).
plugins {
    alias(libs.plugins.android.application) apply false
    alias(libs.plugins.kotlin.android) apply false
    alias(libs.plugins.kotlin.jvm) apply false
    alias(libs.plugins.kotlin.serialization) apply false
    alias(libs.plugins.compose.compiler) apply false
}

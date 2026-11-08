package ph.edu.isatu.visitor.data

import com.google.gson.FieldNamingPolicy
import com.google.gson.Gson
import com.google.gson.GsonBuilder
import com.google.gson.JsonObject
import com.google.gson.JsonParser
import com.google.gson.TypeAdapter
import com.google.gson.TypeAdapterFactory
import com.google.gson.reflect.TypeToken
import com.google.gson.stream.JsonReader
import com.google.gson.stream.JsonToken
import com.google.gson.stream.JsonWriter
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import ph.edu.isatu.visitor.BuildConfig
import retrofit2.Response
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

class ApiException(
    val statusCode: Int,
    override val message: String,
    val fieldErrors: Map<String, String> = emptyMap(),
) : Exception(message)

/**
 * How the app reads the server's JSON. PHP sends an empty array as `[]` even where it means an
 * object, so an empty `[]` in place of one of the app's objects reads as an empty object: a
 * reply with nothing in it never fails. A `[]` with something in it still fails, as it should.
 */
internal fun apiGson(): Gson = GsonBuilder()
    .setFieldNamingPolicy(FieldNamingPolicy.LOWER_CASE_WITH_UNDERSCORES)
    .registerTypeAdapterFactory(EmptyArrayAsObject)
    .create()

private object EmptyArrayAsObject : TypeAdapterFactory {
    override fun <T> create(gson: Gson, type: TypeToken<T>): TypeAdapter<T>? {
        // Only the app's own reply classes. Maps already read [] as empty, and lists are lists.
        val raw = type.rawType
        if (!raw.name.startsWith("ph.edu.isatu.visitor.") || raw.isEnum) return null
        val delegate = gson.getDelegateAdapter(this, type)
        return object : TypeAdapter<T>() {
            override fun write(out: JsonWriter, value: T?) = delegate.write(out, value)

            override fun read(reader: JsonReader): T? {
                if (reader.peek() != JsonToken.BEGIN_ARRAY) return delegate.read(reader)
                val array = JsonParser.parseReader(reader).asJsonArray
                return delegate.fromJsonTree(if (array.isEmpty) JsonObject() else array)
            }
        }
    }
}

class ApiClient(private val tokenStore: SecureTokenStore) {
    val gson: Gson = apiGson()

    private val authInterceptor = Interceptor { chain ->
        val original = chain.request()
        val builder = original.newBuilder()
            .header("Accept", "application/json")
            .header("User-Agent", "ISATU-Visitor-Android/${BuildConfig.VERSION_NAME}")
        tokenStore.readToken()?.let { builder.header("Authorization", "Bearer $it") }
        val response = chain.proceed(builder.build())
        if (response.code == 401 && !original.url.encodedPath.endsWith("/auth/login.php")) {
            tokenStore.clear()
        }
        response
    }

    private val client = OkHttpClient.Builder()
        .addInterceptor(authInterceptor)
        .apply {
            if (BuildConfig.DEBUG) {
                addInterceptor(HttpLoggingInterceptor().apply {
                    level = HttpLoggingInterceptor.Level.BASIC
                    redactHeader("Authorization")
                })
            }
        }
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .writeTimeout(30, TimeUnit.SECONDS)
        .build()

    val api: VisitorApi = Retrofit.Builder()
        .baseUrl(BuildConfig.API_BASE_URL)
        .client(client)
        .addConverterFactory(GsonConverterFactory.create(gson))
        .build()
        .create(VisitorApi::class.java)

    fun <T> requireData(response: Response<ApiEnvelope<T>>): T {
        val body = response.body()
        if (response.isSuccessful && body?.success == true && body.data != null) return body.data
        val errorEnvelope = response.errorBody()?.string()?.let { raw ->
            runCatching {
                val type = object : TypeToken<ApiEnvelope<Any?>>() {}.type
                gson.fromJson<ApiEnvelope<Any?>>(raw, type)
            }.getOrNull()
        }
        val fieldErrors = errorEnvelope?.errors.orEmpty().mapValues { (_, value) -> value?.toString().orEmpty() }
        throw ApiException(
            statusCode = response.code(),
            message = errorEnvelope?.message ?: body?.message ?: "The server could not complete the request.",
            fieldErrors = fieldErrors,
        )
    }
}

